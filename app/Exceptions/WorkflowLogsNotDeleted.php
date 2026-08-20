<?php

namespace App\Exceptions;

use Exception;

class WorkflowLogsNotDeleted extends Exception
{
    public function __construct(string $workflowName)
    {
        parent::__construct("Failed to delete the log records of workflow [{$workflowName}].");
    }
}
