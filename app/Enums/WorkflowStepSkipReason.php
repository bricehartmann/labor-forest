<?php

namespace App\Enums;

enum WorkflowStepSkipReason: string
{
    case NOT_SELECTED = 'not-selected';
    case IF_FAILED = 'if-failed';
    case UNLESS_MATCHED = 'unless-matched';
}
