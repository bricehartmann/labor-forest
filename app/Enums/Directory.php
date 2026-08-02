<?php

namespace App\Enums;

enum Directory: string
{
    case BASE = '.labor-forest';
    case IGNORED = 'ignored';
    case WORKFLOWS = 'workflows';
}
