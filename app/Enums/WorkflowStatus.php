<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;

enum WorkflowStatus: string implements HasColor
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case SUCCESS = 'success';
    case FAILED = 'failed';

    public function getColor(): string
    {
        return match ($this) {
            self::PENDING => 'gray',
            self::RUNNING => 'warning',
            self::SUCCESS => 'success',
            self::FAILED => 'danger',
        };
    }

    public function isLocked(): bool
    {
        return match ($this) {
            self::PENDING, self::RUNNING => true,
            self::SUCCESS, self::FAILED => false,
        };
    }
}
