<?php

namespace App\Data;

use App\Enums\GitStatus;
use App\Enums\WorkspaceStatus;
use Illuminate\Support\Str;
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
        #[WithCast(EnumCast::class)]
        public GitStatus $git_status,
    ) {}

    public function dirName(): string
    {
        return str($this->path)->afterLast(DIRECTORY_SEPARATOR)->toString();
    }

    public function parentDir(): string
    {
        return dirname($this->path);
    }

    public function slugKebab(): string
    {
        return Str::slug($this->dirName());
    }

    public function slugSnake(): string
    {
        return Str::slug($this->dirName(), '_');
    }
}
