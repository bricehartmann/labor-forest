<?php

namespace App\Exceptions;

use Exception;

class ProjectDirectoryExists extends Exception
{
    public function __construct(string $path)
    {
        parent::__construct("Project with directory '{$path}' already exists.");
    }
}
