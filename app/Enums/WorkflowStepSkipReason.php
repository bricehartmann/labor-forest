<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum WorkflowStepSkipReason: string implements HasLabel
{
    case NOT_SELECTED = 'not-selected';
    case IF_FAILED = 'if-failed';
    case UNLESS_MATCHED = 'unless-matched';

    public function getLabel(): string
    {
        return match ($this) {
            self::NOT_SELECTED => 'not selected to run',
            self::IF_FAILED => 'if condition returned false',
            self::UNLESS_MATCHED => 'unless condition returned true',
        };
    }
}
