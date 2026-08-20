<?php

namespace App\Enums;

enum FileExtension: string
{
    case YAML = 'yaml';
    case YML = 'yml';

    /**
     * Whether the extension names a YAML file, in either of its spellings.
     *
     * Workflow files are authored by hand, so both spellings are read. Everything LaborForest
     * writes itself still uses `yaml`, which is why this is a read-side question only.
     */
    public static function isYaml(?string $extension): bool
    {
        return in_array($extension, [self::YAML->value, self::YML->value], strict: true);
    }
}
