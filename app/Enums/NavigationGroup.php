<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum NavigationGroup: string implements HasLabel
{
    case PROJECTS = 'projects';

    public function getLabel(): string
    {
        return ucwords($this->value);
    }
}
