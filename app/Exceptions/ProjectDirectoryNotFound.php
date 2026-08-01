<?php

namespace App\Exceptions;

use Exception;

class ProjectDirectoryNotFound extends Exception
{
    public function __construct(string $path)
    {
        parent::__construct("Project directory '{$path}' not found.");
    }
}
