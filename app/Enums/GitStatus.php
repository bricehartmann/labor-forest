<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum GitStatus: string implements HasColor, HasLabel
{
    case CLEAN = 'clean';
    case DIRTY = 'dirty';
    case UNKNOWN = 'unknown';

    public function getLabel(): string
    {
        return $this->value;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::CLEAN => 'success',
            self::DIRTY => 'warning',
            self::UNKNOWN => 'gray',
        };
    }
}
