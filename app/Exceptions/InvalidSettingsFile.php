<?php

namespace App\Exceptions;

final class InvalidSettingsFile extends InvalidYamlFile
{
    protected static function label(): string
    {
        return 'settings';
    }
}
