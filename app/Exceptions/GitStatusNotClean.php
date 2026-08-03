<?php

namespace App\Exceptions;

use Exception;

class GitStatusNotClean extends Exception
{
    public function __construct(string $path)
    {
        parent::__construct("Project with directory '{$path}' has uncommitted changes. Commit or stash them before adding the project.");
    }
}
