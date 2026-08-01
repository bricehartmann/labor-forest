<?php

namespace App\Enums;

enum WorkflowStepType: string
{
    case COPY_FROM_PRIMARY_DIR = 'copy_from_primary_dir';
    case UPDATE_ENV = 'update_env';
    case SHELL = 'shell';
    case WORKFLOW = 'workflow';
}
