<?php

namespace App\Enums;

enum WorkspaceStatus: string
{
    case READY = 'ready';
    case CHANGING = 'changing';
    case SUSPENDED = 'suspended';
    case ERROR = 'error';
}
