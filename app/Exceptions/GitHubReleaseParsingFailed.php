<?php

namespace App\Exceptions;

use Exception;
use Throwable;

class GitHubReleaseParsingFailed extends Exception
{
    public function __construct(string $payload, ?Throwable $previous = null)
    {
        parent::__construct("Failed to parse GitHub release: $payload", previous: $previous);
    }
}
