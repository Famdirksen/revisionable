<?php

namespace Famdirksen\Revisionable;

use Illuminate\Support\ServiceProvider;

class RevisionableServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        // Define the path to the migrations directory
        $migrationPath = __DIR__.'/migrations';

        // Recommended approach: Load migrations directly from the package
        $this->loadMigrationsFrom($migrationPath);

        // Optional: Allow users to publish the migrations if they need to customize them
        if ($this->app->runningInConsole()) {
            $this->publishes([
                $migrationPath => database_path('migrations')
            ], 'revisionable-migrations');
        }
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        //
    }
}