<?php

namespace App\Exceptions;

use Exception;

class ProjectNotFound extends Exception
{
    public function __construct(string $uuid)
    {
        parent::__construct("Project with UUID '{$uuid}' not found.");
    }
}
