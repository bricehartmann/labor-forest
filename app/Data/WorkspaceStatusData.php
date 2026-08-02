<?php

namespace App\Data;

use App\Enums\WorkspaceStatus;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\EnumCast;
use Spatie\LaravelData\Data;

class WorkspaceStatusData extends Data
{
    public function __construct(
        #[WithCast(EnumCast::class)]
        public WorkspaceStatus $status,
    ) {}
}
