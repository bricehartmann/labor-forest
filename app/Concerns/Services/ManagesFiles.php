<?php

namespace App\Concerns\Services;

use App\Enums\Directory;
use App\Enums\Disk;
use Illuminate\Support\Facades\Storage;

trait ManagesFiles
{
    protected function ensureDirectoryExists(string $path, string $disk): void
    {
        if (! Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->makeDirectory($path);
        }
    }

    protected function ensureFileExists(string $path, string $disk, ?string $contents = null): void
    {
        if (! Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->put($path, $contents ?? '');
        }
    }

    protected function ensureBaseFileExists(string $file, ?string $contents = null): void
    {
        $this->ensureFileExists($this->makeRelativeBasePath($file), Disk::USER_HOME->value, $contents);
    }

    protected function putBaseFile(string $file, string $contents): void
    {
        Storage::disk(Disk::USER_HOME->value)->put($this->makeRelativeBasePath($file), $contents);
    }

    protected function getBaseFile(string $file): string
    {
        return Storage::disk(Disk::USER_HOME->value)->get($this->makeRelativeBasePath($file));
    }

    protected function makeRelativeBasePath(string $relative = ''): string
    {
        return rtrim(Directory::BASE->value.'/'.$relative, '/');
    }

    protected function ensureBaseDirectoryExists(): void
    {
        $this->ensureDirectoryExists(Directory::BASE->value, Disk::USER_HOME->value);
    }
}
