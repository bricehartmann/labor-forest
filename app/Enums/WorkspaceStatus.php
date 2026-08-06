<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum WorkspaceStatus: string implements HasColor, HasLabel
{
    case PENDING = 'pending';
    case READY = 'ready';
    case WORKING = 'working';
    case SUSPENDED = 'suspended';
    case ERROR = 'error';
    case UNKNOWN = 'unknown';

    public function getLabel(): string
    {
        return $this->value;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::READY => 'success',
            self::WORKING => 'warning',
            self::PENDING => 'info',
            self::SUSPENDED, self::UNKNOWN => 'gray',
            self::ERROR => 'danger',
        };
    }

    public function ableToRunWorkflow(): bool
    {
        return match ($this) {
            self::READY, self::SUSPENDED => true,
            default => false,
        };
    }
}
