<?php

namespace App\Enums;

enum HostEnvKey: string
{
    /**
     * Matches an environment variable injected by the NativePHP runtime.
     */
    public const string INJECTED = '/^(NATIVEPHP|LARAVEL)_/';

    /**
     * Environment variables a spawned process keeps even when this application declares them.
     */
    public const array PRESERVED = [
        'PATH',
        'HOME',
        'USER',
        'SHELL',
        'TMPDIR',
        'SSH_AUTH_SOCK',
        'TERM',
        'LANG',
    ];

    /**
     * Environment variables forced into every spawned process so tools colorize their output even
     * though a piped process gives them no terminal to detect.
     */
    public const array FORCED = [
        'FORCE_COLOR' => '3',
        'CLICOLOR_FORCE' => '1',
    ];

    /**
     * Terminal type given to a spawned process when the host environment declares none, as happens
     * in a packaged build launched outside a terminal.
     */
    public const string FALLBACK_TERM = 'xterm-256color';

    case APP_ENV = 'APP_ENV';
    case APP_DEBUG = 'APP_DEBUG';
    case APP_CONFIG_CACHE = 'APP_CONFIG_CACHE';
    case APP_SERVICES_CACHE = 'APP_SERVICES_CACHE';
    case APP_PACKAGES_CACHE = 'APP_PACKAGES_CACHE';
    case APP_ROUTES_CACHE = 'APP_ROUTES_CACHE';
    case APP_EVENTS_CACHE = 'APP_EVENTS_CACHE';

    /**
     * Determine whether the variable name is injected by the NativePHP runtime.
     */
    public static function isInjectedName(string $name): bool
    {
        return preg_match(self::INJECTED, $name) === 1;
    }

    /**
     * Determine whether the variable name must reach a spawned process untouched.
     */
    public static function isPreservedName(string $name): bool
    {
        return in_array($name, self::PRESERVED, true) || str_starts_with($name, 'LC_');
    }
}
