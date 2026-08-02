<?php

namespace App\Enums;

enum File: string
{
    case SETTINGS = 'settings.yaml';
    case PROJECTS = 'projects.yaml';
    case WORKFLOW_UP = 'up.yaml';
    case WORKFLOW_DOWN = 'down.yaml';
}
