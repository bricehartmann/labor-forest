<?php

namespace App\Enums;

enum WorkflowStepType: string
{
    case UPDATE_ENV = 'update_env';
    case SHELL = 'shell';
    case WORKFLOW = 'workflow';
}
