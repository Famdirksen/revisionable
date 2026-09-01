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
                    'default_type' => true, // Default auth guard used, just a heads up for other classess...

                    'type' => get_class($user),
                    'id' => $user->getAuthIdentifier(),
                    'guard' => config('auth.defaults.guard'),
                ];
            }

            // Check all other auth-guards for logged in states. Each guard is
            // tried in its own try/catch: a guard that fails to even construct
            // (e.g. Passport's guard when its RSA keys aren't readable on this
            // server) must not prevent checking the remaining guards, or abort
            // system-user detection entirely.
            foreach (app('config')->get('auth.guards', []) as $guard => $guardConfig) {
                try {
                    $authGuard = \Auth::guard($guard);

                    if ($authGuard->check()) {
                        if (is_bool($authGuard->user())) {
                            return null;
                        }

                        return [
                            'type' => get_class($authGuard->user()),
                            'id' => $authGuard->user()->getAuthIdentifier(),
                            'guard' => $guard,
                        ];
                    }
                } catch (\Throwable $e) {
                    $this->reportException($e);
                    continue;
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

            if ($token && $this->isPassportToken($user, $token)) {
                return null;
            }

            if ($token) {
                return $token->id;
            }
        }

        return null;
    }

    /**
     * Whether the token returned by currentAccessToken() is in fact the Passport
     * OAuth token, which belongs in passport_token_id instead.
     *
     * An application may expose a single currentAccessToken() that returns either
     * a Sanctum personal access token or a Passport OAuth token, so both guards
     * can be used side by side — Sanctum's and Passport's HasApiTokens traits
     * collide on this method name, so they cannot both be applied to one model.
     * Without this check the Passport token is reported as a Sanctum PAT and the
     * same id is written to api_token_id and passport_token_id.
     *
     * Detected two ways, because neither alone is sufficient. The class check
     * covers Passport's own token types across major versions -- the list is
     * deliberately broad since `instanceof` against a class that is not
     * installed simply yields false, and a Sanctum token can never match a
     * Laravel\Passport type. The identity check then covers applications that
     * hand back a wrapper or proxy of their own class: if the model's Passport
     * accessor returns this very object, it is the Passport token whatever its
     * class.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable|mixed  $user
     * @param  mixed  $token
     * @return bool
     */
    protected function isPassportToken($user, $token)
    {
        // ScopeAuthorizable covers AccessToken and TransientToken on Passport 13+;
        // Token is the Eloquent model older token guards hand over and does not
        // implement that contract. Entries for classes that are not installed are
        // harmless -- `instanceof` against an unknown class is false and triggers
        // no autoloading. Kept as a local array rather than a constant, because
        // constants in traits require PHP 8.2 and this package supports far older.
        $passportTokenTypes = array(
            'Laravel\Passport\Contracts\ScopeAuthorizable',
            'Laravel\Passport\AccessToken',
            'Laravel\Passport\Token',
            'Laravel\Passport\TransientToken',
        );

        foreach ($passportTokenTypes as $type) {
            if ($token instanceof $type) {
                return true;
            }
        }

        return method_exists($user, 'token') && $user->token() === $token;
    }

    /**
     * Attempt to get the ID of the current Passport OAuth token, kept in its own
     * column (see getApiTokenId()) so it can't collide with a Sanctum PAT id.
     *
     * Opt-in: returns null unless `revisionable.store_passport_token` is true.
     * Existing consumers that never touch this config key see no change.
     *
     * @return string|null
     */
    protected function getPassportTokenId()
    {
        if (! config('revisionable.store_passport_token', false)) {
            return null;
        }

        $user = request()->user() ?? \Illuminate\Support\Facades\Auth::user();

        if (! $user) {
            return null;
        }

        if (method_exists($user, 'shouldStoreUsedApiToken') && ! $user->shouldStoreUsedApiToken()) {
            return null;
        }

        if (method_exists($user, 'token')) {
            $token = $user->token();

            if ($token) {
                return $token->id;
            }
        }

        return null;
    }
}
