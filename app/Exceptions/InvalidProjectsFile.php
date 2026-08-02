<?php

namespace App\Exceptions;

class InvalidProjectsFile extends InvalidYamlFile
{
    protected static function label(): string
    {
        return 'projects';
    }

    /**
     * The file parsed, but holds something other than a list of projects.
     */
    public static function notAList(string $path, string $actualType): self
    {
        return new self($path, [
            sprintf('Expected a list of projects, found %s.', $actualType),
        ]);
    }
}
