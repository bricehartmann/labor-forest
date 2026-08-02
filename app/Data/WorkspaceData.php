<?php

namespace App\Data;

use App\Enums\WorkspaceStatus;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\EnumCast;
use Spatie\LaravelData\Data;

class WorkspaceData extends Data
{
    public function __construct(
        public string $path,
        public string $branch,
        #[WithCast(EnumCast::class)]
        public WorkspaceStatus $status,
    ) {}
}
