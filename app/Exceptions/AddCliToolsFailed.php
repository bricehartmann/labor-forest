<?php

namespace App\Exceptions;

use Exception;

class AddCliToolsFailed extends Exception
{
    public function __construct(string $path)
    {
        parent::__construct("Failed to add CLI tools to: '{$path}'");
    }
}
