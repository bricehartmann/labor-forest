<?php

namespace App\Enums;

enum WorkflowStepStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case SUCCESS = 'success';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';
}
