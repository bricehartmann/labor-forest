<?php

namespace App\Exceptions;

use Exception;

final class UnresolvedVariable extends Exception
{
    /**
     * The placeholder is neither an enumerated variable nor an environment passthrough.
     */
    public static function unknownVariable(string $placeholder): self
    {
        return new self(sprintf('Unknown variable %s.', $placeholder));
    }

    /**
     * The placeholder is a well-formed passthrough, but the workspace .env does not define it.
     */
    public static function missingEnvironmentVariable(string $name, string $path): self
    {
        return new self(sprintf(
            "Environment variable '%s' not found in '%s'.",
            substr($name, strlen('ENV_')),
            $path.DIRECTORY_SEPARATOR.'.env',
        ));
    }

    /**
     * The regular expression engine failed part way through the replacement.
     */
    public static function replacementFailed(string $error): self
    {
        return new self(sprintf('Failed to replace variables: %s.', $error));
    }
}
