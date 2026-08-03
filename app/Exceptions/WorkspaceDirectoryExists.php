<?php

namespace App\Exceptions;

use Exception;

class WorkspaceDirectoryExists extends Exception
{
    public function __construct(string $path)
    {
        parent::__construct("Workspace with directory '{$path}' already exists.");
    }
}
