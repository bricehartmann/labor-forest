<?php

namespace App\Exceptions;

final class InvalidWorkflowFile extends InvalidYamlFile
{
    protected static function label(): string
    {
        return 'workflow';
    }
}
