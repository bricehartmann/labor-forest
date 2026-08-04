<?php

namespace App\Enums;

enum WorkflowKnownName: string
{
    case UP = 'up';
    case DOWN = 'down';

    /**
     * The workspace status a workflow requires in order to be runnable.
     */
    public function requiredWorkspaceStatus(): WorkspaceStatus
    {
        return match ($this) {
            self::UP => WorkspaceStatus::SUSPENDED,
            self::DOWN => WorkspaceStatus::READY,
        };
    }
}
