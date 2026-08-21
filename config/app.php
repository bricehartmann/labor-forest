<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Latest Release URL
    |--------------------------------------------------------------------------
    |
    | The GitHub API endpoint the dashboard checks for a newer release than the
    | installed one. It is the release *list*, not /releases/latest, which 404s
    | while every published release is a prerelease. GitHubService picks the
    | newest stable out of it and falls back to the newest prerelease only when
    | there is no stable one. 100 is the API's page size limit, so a stable
    | release buried under more than 100 newer prereleases would be missed.
    |
    */

    'latest_release_url' => 'https://api.github.com/repos/bricehartmann/labor-forest/releases?per_page=100',

    /*
    |--------------------------------------------------------------------------
    | Documentation URL
    |--------------------------------------------------------------------------
    |
    | A packaged build opens the documentation as it stood at the release tag
    | it was built from, so an older install never reads docs for features it
    | does not have. Everything else — dev runs and tests — reads "main",
    | because the version may already be bumped past the newest pushed tag.
    |
    */

    'docs_url' => sprintf(
        'https://github.com/bricehartmann/labor-forest/blob/%s/docs/_index.md',
        env('APP_ENV') === 'production'
            ? (env('NATIVEPHP_APP_VERSION') ?: 'main')
            : 'main',
    ),

    /*
    |--------------------------------------------------------------------------
    | License URL
    |--------------------------------------------------------------------------
    |
    | Where the dashboard sends a user asking to read the license. A packaged
    | build points at the license as it stood at the release tag it was built
    | from — the terms that build was actually conveyed under. Everything
    | else reads "main", as the documentation URL above does.
    |
    */

    'license_url' => sprintf(
        'https://github.com/bricehartmann/labor-forest/blob/%s/LICENSE.md',
        env('APP_ENV') === 'production'
            ? (env('NATIVEPHP_APP_VERSION') ?: 'main')
            : 'main',
    ),

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
