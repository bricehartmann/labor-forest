<?php

namespace App\Enums;

enum WorkflowStepType: string
{
    case UPDATE_ENV = 'update_env';
    case SHELL = 'shell';
    case WORKFLOW = 'workflow';

    /**
     * The step key this type cannot be written without.
     *
     * Kept in step with the `required_if` rules on WorkflowStepData, which are what actually reject
     * a step missing it; this side of the pair is what the MCP workflow schema is built from.
     */
    public function requiredKey(): string
    {
        return match ($this) {
            self::SHELL, self::WORKFLOW => 'run',
            self::UPDATE_ENV => 'map',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SHELL => 'Runs `run` as a shell command in the workspace directory.',
            self::UPDATE_ENV => 'Rewrites each key of `map` in the workspace\'s own .env file, appending a key that is not already there.',
            self::WORKFLOW => 'Runs the workflow named by `run` inline, as part of this run rather than as a separate one.',
        };
    }
}
