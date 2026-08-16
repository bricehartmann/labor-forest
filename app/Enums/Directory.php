<?php

namespace App\Enums;

enum Directory: string
{
    case BASE = '.laborforest';
    case IGNORED = 'ignored';
    case WORKFLOWS = 'workflows';
    case LOGS = 'logs';
    case EXAMPLE_WORKFLOWS = 'example-workflows';
    case GIT_INFO = 'info';
    case BIN = 'bin';
}
