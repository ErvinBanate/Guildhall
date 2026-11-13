<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The hosting proxy terminates TLS. Generate links for the configured public origin.
        $origin = config('app.url');
        if ($this->app->environment('production') && is_string($origin) && str_starts_with($origin, 'https://')) {
            \Illuminate\Support\Facades\URL::forceRootUrl(rtrim($origin, '/'));
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
    }
}
