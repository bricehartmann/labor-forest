<?php

namespace App\Exceptions;

use App\Enums\WorkspaceStatus;
use Exception;

class WorkflowNotRunnable extends Exception
{
    public function __construct(string $workflowName, WorkspaceStatus $currentStatus, ?WorkspaceStatus $requiredStatus)
    {
        parent::__construct($requiredStatus === null
            ? "Workflow [{$workflowName}] cannot run while the workspace is {$currentStatus->value}."
            : "Workflow [{$workflowName}] requires the workspace to be {$requiredStatus->value}, but it is {$currentStatus->value}.");
    }
}
