<?php

namespace App\Exceptions;

class InvalidWorkflowFile extends InvalidYamlFile
{
    protected static function label(): string
    {
        return 'workflow';
    }
}
