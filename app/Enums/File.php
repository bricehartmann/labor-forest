<?php

namespace App\Enums;

enum File: string
{
    case SETTINGS = 'settings.yaml';
    case PROJECTS = 'projects.yaml';
    case GIT_IGNORE = '.gitignore';
    case ENV = '.env';
    case STATUS = 'status.yaml';
}
