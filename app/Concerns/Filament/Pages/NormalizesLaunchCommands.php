<?php

namespace App\Concerns\Filament\Pages;

trait NormalizesLaunchCommands
{
    /**
     * Store a cleared launch command as null rather than an empty string.
     *
     * An empty string is a value a launch resolves to, so it would shadow the command it should fall
     * back to instead of reading as "not set".
     */
    protected static function blankToNull(?string $state): ?string
    {
        return filled($state) ? $state : null;
    }
}
