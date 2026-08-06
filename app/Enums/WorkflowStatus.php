<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;

enum WorkflowStatus: string implements HasColor
{
    case RUNNING = 'running';
    case SUCCESS = 'success';
    case FAILED = 'failed';

    public function getColor(): string
    {
        return match ($this) {
            self::RUNNING => 'warning',
            self::SUCCESS => 'success',
            self::FAILED => 'danger',
        };
    }
}
