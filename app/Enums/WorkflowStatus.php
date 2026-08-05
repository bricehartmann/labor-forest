<?php

namespace App\Enums;

enum WorkflowStatus: string
{
    case RUNNING = 'running';
    case SUCCESS = 'success';
    case FAILED = 'failed';
}
