<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum WorkspaceStatus: string implements HasColor, HasLabel
{
    case READY = 'ready';
    case CHANGING = 'changing';
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
            self::CHANGING => 'warning',
            self::SUSPENDED, self::UNKNOWN => 'gray',
            self::ERROR => 'danger',
        };
    }
}
