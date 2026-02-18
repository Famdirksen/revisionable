<?php

namespace Famdirksen\Revisionable;

use Illuminate\Support\Facades\Request;

/**
 * Class SystemUserTrait
 * @package Famdirksen\Revisionable
 */
trait SystemUserTrait
{
    use ExceptionReportTrait;

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
     * Supports Laravel Sanctum and Laravel Passport.
     *
     * @return string|int|null
     */
    protected function getApiTokenId()
    {
        // 1. Check config (fastest check first)
        if (!config('revisionable.store_api_token', false)) {
            return null;
        }

        // 2. Get user (cached in memory by Laravel)
        $user = request()->user() ?? \Illuminate\Support\Facades\Auth::user();

        if (!$user) {
            return null;
        }

        // 3. Check optional user method override
        // This allows the user model to explicitly allow/disallow token storage
        if (method_exists($user, 'shouldStoreUsedApiToken')) {
            if (!$user->shouldStoreUsedApiToken()) {
                return null;
            }
        } 

        // 4. Sanctum check
        if (method_exists($user, 'currentAccessToken')) {
            $token = $user->currentAccessToken();
            if ($token) {
                return $token->id;
            }
        }

        // 5. Passport check
        if (method_exists($user, 'token')) {
            $token = $user->token();
            if ($token) {
                return $token->id;
            }
        }

        return null;
    }
}
