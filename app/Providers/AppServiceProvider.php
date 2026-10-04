<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use App\Support\Mail\PlatformSmtpConfiguration;

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
        Paginator::useBootstrap();
        try {
            app(PlatformSmtpConfiguration::class)->apply();
        } catch (\Throwable $exception) {
            // Runtime mail configuration is optional; never log settings values.
            report($exception);
        }
    }
}
