<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use App\Support\Mail\PlatformSmtpConfiguration;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Production TLS terminates at Caddy; the Apache upstream uses HTTP.
        // Generate browser URLs with HTTPS without trusting arbitrary proxy headers.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        Paginator::useBootstrap();
        try {
            app(PlatformSmtpConfiguration::class)->apply();
        } catch (\Throwable $exception) {
            // Runtime mail configuration is optional; never log settings values.
            report($exception);
        }
    }
}
