<?php

namespace App\Enums;

enum File: string
{
    case SETTINGS = 'settings.yaml';
    case PROJECTS = 'projects.yaml';
    case GIT_IGNORE = '.gitignore';
    case GIT_EXCLUDE = 'exclude';
    case ENV = '.env';
    case STATUS = 'status.yaml';
    case CLI_TOOLS = 'lf';
    case PENDING_CLI_COMMAND = 'pending.yaml';
}
