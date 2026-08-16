<?php

namespace App\Exceptions;

use Exception;

class GitHubReleasesNotFound extends Exception
{
    public function __construct(string $url)
    {
        parent::__construct("No releases found at URL: {$url}");
    }
}
