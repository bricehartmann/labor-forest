<?php

namespace App\Exceptions;

class InvalidSettingsFile extends InvalidYamlFile
{
    protected static function label(): string
    {
        return 'settings';
    }
}
