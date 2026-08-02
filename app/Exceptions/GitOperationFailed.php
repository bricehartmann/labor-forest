<?php

namespace App\Exceptions;

use Exception;

class GitOperationFailed extends Exception
{
    public function __construct(string $operation, string $output)
    {
        parent::__construct('Failed to '.$operation.': '.$output);
    }
}
