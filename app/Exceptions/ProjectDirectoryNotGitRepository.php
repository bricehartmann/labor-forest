<?php

namespace App\Exceptions;

use Exception;

class ProjectDirectoryNotGitRepository extends Exception
{
    public function __construct(string $path)
    {
        parent::__construct("Project with directory '{$path}' is not a git repository.");
    }
}
