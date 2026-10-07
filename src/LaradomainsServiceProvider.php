<?php

namespace EduLazaro\Laradomains;

use EduLazaro\Laradomains\Console\UpdateSuffixes;
use EduLazaro\Laradomains\Support\Http;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the package into the application. Auto-discovered; publishes `laradomains-config`.
 */
class LaradomainsServiceProvider extends ServiceProvider
{
    /** Binds the manager as the "laradomains" singleton, which the facade resolves. */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laradomains.php', 'laradomains');

        // Hooks belong to one application. Without this, every app booted in the same process
        // (one per test) would add its hooks on top of the previous ones.
        Http::flushHooks();

        $this->app->singleton('laradomains', fn ($app) => $app->build(Laradomains::class));
        $this->app->alias('laradomains', Laradomains::class);
    }

    /** Declares the publishable config and the console command. */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/laradomains.php' => config_path('laradomains.php'),
        ], 'laradomains-config');

        if ($this->app->runningInConsole()) {
            $this->commands([UpdateSuffixes::class]);
        }
    }
}
