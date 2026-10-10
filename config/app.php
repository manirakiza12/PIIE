<?php

use Illuminate\Support\Facades\Facade;

// Based on the official laravel/laravel 9.x application configuration.
return [
    'name' => env('APP_NAME', 'Prime International Institute of Excellence'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    // Development-only: honoured solely when AdminController::subscriptionBypassPermitted()
    // confirms APP_ENV=local AND the isolated dev database on port 3307. Off by default.
    'enforce_school_subscriptions' => env('ENFORCE_SCHOOL_SUBSCRIPTIONS', false),
    'bypass_subscription' => filter_var(env('BYPASS_SUBSCRIPTION', false), FILTER_VALIDATE_BOOLEAN),
    'url' => env('APP_URL', 'http://localhost'),
    'asset_url' => env('ASSET_URL'),

    'timezone' => env('APP_TIMEZONE', 'UTC'),
    'locale' => env('APP_LOCALE', 'en'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),
    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    'key' => env('APP_KEY'),
    'cipher' => 'AES-256-CBC',

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
    ],

    'providers' => [
        // Laravel 9 framework service providers.
        Illuminate\Auth\AuthServiceProvider::class,
        Illuminate\Broadcasting\BroadcastServiceProvider::class,
        Illuminate\Bus\BusServiceProvider::class,
        Illuminate\Cache\CacheServiceProvider::class,
        Illuminate\Foundation\Providers\ConsoleSupportServiceProvider::class,
        Illuminate\Cookie\CookieServiceProvider::class,
        Illuminate\Database\DatabaseServiceProvider::class,
        Illuminate\Encryption\EncryptionServiceProvider::class,
        Illuminate\Filesystem\FilesystemServiceProvider::class,
        Illuminate\Foundation\Providers\FoundationServiceProvider::class,
        Illuminate\Hashing\HashServiceProvider::class,
        Illuminate\Mail\MailServiceProvider::class,
        Illuminate\Notifications\NotificationServiceProvider::class,
        Illuminate\Pagination\PaginationServiceProvider::class,
        Illuminate\Pipeline\PipelineServiceProvider::class,
        Illuminate\Queue\QueueServiceProvider::class,
        Illuminate\Redis\RedisServiceProvider::class,
        Illuminate\Auth\Passwords\PasswordResetServiceProvider::class,
        Illuminate\Session\SessionServiceProvider::class,
        Illuminate\Translation\TranslationServiceProvider::class,
        Illuminate\Validation\ValidationServiceProvider::class,
        Illuminate\View\ViewServiceProvider::class,

        // Third-party providers are registered through Composer package discovery.

        // Application providers: settings, policies, audit events, routes and observers.
        App\Providers\AppServiceProvider::class,
        App\Providers\AuthServiceProvider::class,
        // App\Providers\BroadcastServiceProvider::class,
        App\Providers\EventServiceProvider::class,
        App\Providers\RouteServiceProvider::class,
        App\Providers\AuditServiceProvider::class,
        // Supplies the lecturer's Course Offering workspace navigation to every
        // page in the teacher/course_offerings namespace, so that no page can
        // reach production without it - the previous defect was a hand-written
        // tab strip that existed on the Overview page and nowhere else.
        App\Providers\CourseOfferingWorkspaceNavServiceProvider::class,
    ],

    'aliases' => Facade::defaultAliases()->merge([
        // Existing controller aliases, also declared by the installed packages.
        'PDF' => Barryvdh\DomPDF\Facade\Pdf::class,
        'Pdf' => Barryvdh\DomPDF\Facade\Pdf::class,
        'PaytmWallet' => Anand\LaravelPaytmWallet\Facades\PaytmWallet::class,
    ])->toArray(),
];
