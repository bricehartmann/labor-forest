<?php

namespace App\Exceptions;

use Exception;

class GitBranchDoesNotExist extends Exception
{
    public function __construct(string $path, string $branch)
    {
        parent::__construct('Branch "'.$branch.'" does not exist in git repository "'.$path.'"');
    }
}
