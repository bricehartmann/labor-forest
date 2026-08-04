<?php

namespace App\Enums;

enum WorkflowStepSkipReason: string
{
    case NOT_SELECTED = 'not-selected';
    case CONDITION_FAILED = 'condition-failed';
}
