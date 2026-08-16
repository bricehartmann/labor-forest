<?php

namespace App\Exceptions;

use Exception;

class InstallCliToolsFailed extends Exception
{
    public function __construct(string $path)
    {
        parent::__construct("Failed to install CLI tools to: '{$path}'");
    }
}
