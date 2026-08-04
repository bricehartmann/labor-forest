<?php

namespace App\Exceptions;

use Exception;

class WorkspaceNotFound extends Exception
{
    public function __construct(string $path)
    {
        parent::__construct("Workspace at path '{$path}' not found.");
    }
}
