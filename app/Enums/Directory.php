<?php

namespace App\Enums;

enum Directory: string
{
    case BASE = '.laborforest';
    case IGNORED = 'ignored';
    case WORKFLOWS = 'workflows';
    case LOGS = 'logs';
}
