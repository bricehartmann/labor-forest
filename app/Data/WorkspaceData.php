<?php

namespace App\Data;

use App\Enums\WorkspaceStatus;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\EnumCast;
use Spatie\LaravelData\Data;

class WorkspaceData extends Data
{
    public function __construct(
        public bool $is_primary,
        public string $path,
        public string $branch,
        #[WithCast(EnumCast::class)]
        public WorkspaceStatus $status,
    ) {}

    public function dirName(): string
    {
        return str($this->path)->afterLast(DIRECTORY_SEPARATOR)->toString();
    }
}
