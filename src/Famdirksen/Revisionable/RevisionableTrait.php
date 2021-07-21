<?php

namespace Famdirksen\Revisionable;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Arr;

/**
 * Class RevisionableTrait
 * @package Famdirksen\Revisionable
 */
trait RevisionableTrait
{
    use RevisionFormatTrait;
    use ExceptionReportTrait;

    /**
     * @var array
     */
    private $originalData = array();

    /**
     * @var array
     */
    private $updatedData = array();

    /**
     * @var boolean
     */
    private $updating = false;

    /**
     * @var array
     */
    private $dontKeep = array();

    /**
     * @var array
     */
    private $doKeep = array();

    /**
     * Keeps the list of values that have been updated
     *
     * @var array
     */
    protected $dirtyData = array();

    /**
     * Ensure that the bootRevisionableTrait is called only
     * if the current installation is a laravel 4 installation
     * Laravel 5 will call bootRevisionableTrait() automatically
     */
    public static function boot()
    {
        parent::boot();

        if (!method_exists(get_called_class(), 'bootTraits')) {
            static::bootRevisionableTrait();
        }
    }

    /**
     * Create the event listeners for the saving and saved events
     * This lets us save revisions whenever a save is made, no matter the
     * http method.
     *
     */
    public static function bootRevisionableTrait()
    {
        static::saving(function ($model) {
            $model->preSave();
        });

        static::saved(function ($model) {
            $model->postSave();
        });

        static::created(function ($model) {
            $model->postCreate();
        });

        static::deleted(function ($model) {
            $model->preSave();
            $model->postDelete();
        });
    }

    /**
     * @return mixed
     */
    public function revisionHistory()
    {
        return $this->morphMany('\Famdirksen\Revisionable\Revision', 'revisionable');
    }

    /**
     * custom data for the user who created this object
     */
    public function createdHistory()
    {
        $data = $this->revisionHistory;

        foreach ($data as $item) {
            if ($item->key == 'created_at') {
                if (!is_null($item->user_id)) {
                    return $item->userResponsible()->name;
                } else {
                    return 'System';
                }
            }
        }

        return '---';
    }

    /**
     * Generates a list of the last $limit revisions made to any objects of the class it is being called from.
     *
     * @param int $limit
     * @param string $order
     * @return mixed
     */
    public static function classRevisionHistory($limit = 100, $order = 'desc')
    {
        return \Famdirksen\Revisionable\Revision::where('revisionable_type', get_called_class())
            ->orderBy('updated_at', $order)->limit($limit)->get();
    }

    /**
    * Invoked before a model is saved. Return false to abort the operation.
    *
    * @return bool
    */
    public function preSave()
    {
        if (!isset($this->revisionEnabled) || $this->revisionEnabled) {
            // if there's no revisionEnabled. Or if there is, if it's true

            $this->originalData = $this->original;
            $this->updatedData = $this->attributes;

            // we can only safely compare basic items,
            // so for now we drop any object based items, like DateTime
            foreach ($this->updatedData as $key => $val) {
                if (gettype($val) == 'object' && !method_exists($val, '__toString')) {
                    unset($this->originalData[$key]);
                    unset($this->updatedData[$key]);
                    array_push($this->dontKeep, $key);
                }
            }

            // the below is ugly, for sure, but it's required so we can save the standard model
            // then use the keep / dontkeep values for later, in the isRevisionable method
            $this->dontKeep = isset($this->dontKeepRevisionOf) ?
                array_merge($this->dontKeepRevisionOf, $this->dontKeep)
                : $this->dontKeep;

            $this->doKeep = isset($this->keepRevisionOf) ?
                array_merge($this->keepRevisionOf, $this->doKeep)
                : $this->doKeep;

            unset($this->attributes['dontKeepRevisionOf']);
            unset($this->attributes['keepRevisionOf']);

            $this->dirtyData = $this->getDirty();
            $this->updating = $this->exists;
        }
    }

    /**
     * Called after a model is successfully saved.
     *
     * @return void
     * @throws \Exception
     */
    public function postSave()
    {
        if (isset($this->historyLimit) && $this->revisionHistory()->count() >= $this->historyLimit) {
            $LimitReached = true;
        } else {
            $LimitReached = false;
        }
        if (isset($this->revisionCleanup)) {
            $RevisionCleanup = $this->revisionCleanup;
        } else {
            $RevisionCleanup = false;
        }

        // check if the model already exists
        if (((!isset($this->revisionEnabled) || $this->revisionEnabled) && $this->updating) && (!$LimitReached || $RevisionCleanup)) {
            // if it does, it means we're updating
            $changes_to_record = $this->changedRevisionableFields();

            $revisions = array();

            foreach ($changes_to_record as $key => $change) {
                $revisions[] = $this->formatRevision($key, Arr::get($this->originalData, $key), $this->updatedData[$key]);
            }

            if (count($revisions) > 0) {
                if ($LimitReached && $RevisionCleanup) {
                    $toDelete = $this->revisionHistory()->orderBy('id', 'asc')->limit(count($revisions))->get();

                    foreach ($toDelete as $delete) {
                        $delete->delete();
                    }
                }

                $revision = new Revision;
                \DB::table($revision->getTable())->insert($revisions);
                //\Event::fire('revisionable.saved', array('model' => $this, 'revisions' => $revisions));
            }
        }
    }

    /**
    * Called after record successfully created
    */
    public function postCreate()
    {

        // Check if we should store creations in our revision history
        // Set this value to true in your model if you want to
        if (empty($this->revisionCreationsEnabled)) {
            // We should not store creations.
            return false;
        }

        if ((!isset($this->revisionEnabled) || $this->revisionEnabled)) {
            $revisions[] = $this->formatRevision(self::CREATED_AT, null, $this->{self::CREATED_AT});

            $revision = new Revision;
            \DB::table($revision->getTable())->insert($revisions);
            //\Event::fire('revisionable.created', array('model' => $this, 'revisions' => $revisions));
        }
    }

    /**
     * If softdeletes are enabled, store the deleted time
     */
    public function postDelete()
    {
        if ((!isset($this->revisionEnabled) || $this->revisionEnabled)
            && $this->isSoftDelete()
            && $this->isRevisionable($this->getDeletedAtColumn())
        ) {
            $revisions[] = $this->formatRevision($this->getDeletedAtColumn(), null, $this->{$this->getDeletedAtColumn()});

            $revision = new \Famdirksen\Revisionable\Revision;
            \DB::table($revision->getTable())->insert($revisions);
            //\Event::fire('revisionable.deleted', array('model' => $this, 'revisions' => $revisions));
        }
    }

    /**
     * Attempt to find the user id of the currently logged in user
     * Supports Cartalyst Sentry/Sentinel based authentication, as well as stock Auth
     **/
    public function getSystemUserId()
    {
        $systemUser = $this->getSystemUser();

        if(is_null($systemUser)) {
            return $systemUser;
        }

        try {
            if(is_array($systemUser)) {
                if(isset($systemUser['id'])) {
                    return $systemUser['id'];
                }
            }

            throw new \Exception('No `id` found for the authenticated system user.');
        } catch (\Exception $e) {
            $this->reportException($e);
        }

        return null;
    }

    public function getSystemUser()
    {
        try {
            if (class_exists($class = '\SleepingOwl\AdminAuth\Facades\AdminAuth')
                || class_exists($class = '\Cartalyst\Sentry\Facades\Laravel\Sentry')
                || class_exists($class = '\Cartalyst\Sentinel\Laravel\Facades\Sentinel')
            ) {
                if(! $class::check()) {
                    return null;
                }

                return [
                    'type' => $class,
                    'id' => $class::getUser()->id,
                ];
            } elseif (\Auth::check()) {
                $user = \Auth::user();

                return [
                    'default_type' => true, // Default auth guard used, so no need to store user_type...

                    'type' => get_class($user),
                    'id' => $user->getAuthIdentifier(),
                ];
            }

            // Check all other auth-guards for logged in states
            foreach(app('config')->get('auth.guards', []) as $guard => $guardConfig) {
                $authGuard = \Auth::guard($guard);

                if($authGuard->check()) {
                    return [
                        'type' => get_class($authGuard->user()),
                        'id' => $authGuard->user()->getAuthIdentifier(),
                    ];
                }
            }
        } catch (\Exception $e) {
            $this->reportException($e);
        }

        return null;
    }

    private function reportException(\Exception $e) {
        if(function_exists('report')) {
            report($e);
        }
    }

    /**
     * Get all of the changes that have been made, that are also supposed
     * to have their changes recorded
     *
     * @return array fields with new data, that should be recorded
     */
    private function changedRevisionableFields()
    {
        $changes_to_record = array();
        foreach ($this->dirtyData as $key => $value) {
            // check that the field is revisionable, and double check
            // that it's actually new data in case dirty is, well, clean
            if ($this->isRevisionable($key) && !is_array($value)) {
                if (!isset($this->originalData[$key]) || $this->originalData[$key] != $this->updatedData[$key]) {
                    $changes_to_record[$key] = $value;
                }
            } else {
                // we don't need these any more, and they could
                // contain a lot of data, so lets trash them.
                unset($this->updatedData[$key]);
                unset($this->originalData[$key]);
            }
        }

        return $changes_to_record;
    }

    /**
     * Check if this field should have a revision kept
     *
     * @param string $key
     *
     * @return bool
     */
    private function isRevisionable($key)
    {

        // If the field is explicitly revisionable, then return true.
        // If it's explicitly not revisionable, return false.
        // Otherwise, if neither condition is met, only return true if
        // we aren't specifying revisionable fields.
        if (isset($this->doKeep) && in_array($key, $this->doKeep)) {
            return true;
        }
        if (isset($this->dontKeep) && in_array($key, $this->dontKeep)) {
            return false;
        }

        return empty($this->doKeep);
    }

    /**
     * Check if soft deletes are currently enabled on this model
     *
     * @return bool
     */
    private function isSoftDelete()
    {
        // check flag variable used in laravel 4.2+
        if (isset($this->forceDeleting)) {
            return !$this->forceDeleting;
        }

        // otherwise, look for flag used in older versions
        if (isset($this->softDelete)) {
            return $this->softDelete;
        }

        return false;
    }

    /**
     * @return mixed
     */
    public function getRevisionFormattedFields()
    {
        return $this->revisionFormattedFields;
    }

    /**
     * @return mixed
     */
    public function getRevisionFormattedFieldNames()
    {
        return $this->revisionFormattedFieldNames;
    }

    /**
     * Identifiable Name
     * When displaying revision history, when a foreign key is updated
     * instead of displaying the ID, you can choose to display a string
     * of your choice, just override this method in your model
     * By default, it will fall back to the models ID.
     *
     * @return string an identifying name for the model
     */
    public function identifiableName()
    {
        return $this->getKey();
    }

    /**
     * Revision Unknown String
     * When displaying revision history, when a foreign key is updated
     * instead of displaying the ID, you can choose to display a string
     * of your choice, just override this method in your model
     * By default, it will fall back to the models ID.
     *
     * @return string an identifying name for the model
     */
    public function getRevisionNullString()
    {
        return isset($this->revisionNullString) ? $this->revisionNullString : 'nothing';
    }

    /**
     * No revision string
     * When displaying revision history, if the revisions value
     * cant be figured out, this is used instead.
     * It can be overridden.
     *
     * @return string an identifying name for the model
     */
    public function getRevisionUnknownString()
    {
        return isset($this->revisionUnknownString) ? $this->revisionUnknownString : 'unknown';
    }

    /**
     * Disable a revisionable field temporarily
     * Need to do the adding to array longhanded, as there's a
     * PHP bug https://bugs.php.net/bug.php?id=42030
     *
     * @param mixed $field
     *
     * @return void
     */
    public function disableRevisionField($field)
    {
        if (!isset($this->dontKeepRevisionOf)) {
            $this->dontKeepRevisionOf = array();
        }
        if (is_array($field)) {
            foreach ($field as $one_field) {
                $this->disableRevisionField($one_field);
            }
        } else {
            $donts = $this->dontKeepRevisionOf;
            $donts[] = $field;
            $this->dontKeepRevisionOf = $donts;
            unset($donts);
        }
    }

    /**
     * Delete all the revisions from this object
     *
     * @param  array|null  $key_fields
     * @return mixed
     */
    public function deleteRevisions($key_fields = null)
    {
        $query = $this->revisionHistory();

        if (!is_null($key_fields)) {
            $query->whereIn('key', $key_fields);
        }

        return $query->orderBy('id', 'asc')
            ->delete();
    }
}
