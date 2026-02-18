<?php

namespace Famdirksen\Revisionable;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Arr;

/**
 * Class RevisionableTrait
 * @package Famdirksen\Revisionable
 */
trait SystemUserTrait
{
    use ExceptionReportTrait;

    /**
     * Cache the token ID to prevent repeated lookups during the same request loop
     * @var string|int|null
     */
    protected $apiTokenIdCache = null;
    protected $apiTokenIdChecked = false;

    /**
     * Attempt to find the user id of the currently logged in user
     * Supports Cartalyst Sentry/Sentinel based authentication, as well as stock Auth
     **/
    public function getSystemUserId()
    {
        $systemUser = $this->getSystemUser();

        if (is_null($systemUser)) {
            return $systemUser;
        }

        try {
            if (is_array($systemUser)) {
                if (isset($systemUser['id'])) {
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
                if (! $class::check()) {
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
            foreach (app('config')->get('auth.guards', []) as $guard => $guardConfig) {
                $authGuard = \Auth::guard($guard);

                if ($authGuard->check()) {
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
    
    /**
     * Attempt to get the ID of the current API token.
     * Use caching to avoid re-checking for every field update.
     *
     * @return string|int|null
     */
    protected function getApiTokenId()
    {
        if ($this->apiTokenIdChecked) {
            return $this->apiTokenIdCache;
        }

        $this->apiTokenIdChecked = true;

        // Attempt to get the user from the request or the Auth facade
        $user = request()->user() ?? \Illuminate\Support\Facades\Auth::user();

        if (!$user) {
            return $this->apiTokenIdCache = null;
        }

        // Check optional user method override
        if (method_exists($user, 'shouldStoreUsedApiToken')) {
            if (!$user->shouldStoreUsedApiToken()) {
                return $this->apiTokenIdCache = null;
            }
        } 
        // Fallback to config
        elseif (!config('revisionable.store_api_token', false)) {
            return $this->apiTokenIdCache = null;
        }

        // Sanctum check
        if (method_exists($user, 'currentAccessToken')) {
            $token = $user->currentAccessToken();
            if ($token) {
                return $this->apiTokenIdCache = $token->id;
            }
        }

        // Passport check
        if (method_exists($user, 'token')) {
            $token = $user->token();
            if ($token) {
                return $this->apiTokenIdCache = $token->id;
            }
        }

        return $this->apiTokenIdCache = null;
    }
}
