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

    protected function ensureFileExists(string $path, string $disk, ?string $contents = null, bool $private = false): void
    {
        if (! Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->put($path, $contents ?? '', ...($private ? ['private'] : []));
        }
    }

    protected function ensureBaseFileExists(string $file, ?string $contents = null, bool $private = false): void
    {
        $this->ensureFileExists($this->makeRelativeBasePath($file), Disk::USER_HOME->value, $contents, $private);
    }

    protected function baseFileExists(string $file): bool
    {
        return Storage::disk(Disk::USER_HOME->value)->exists($this->makeRelativeBasePath($file));
    }

    protected function deleteBaseFile(string $file): void
    {
        Storage::disk(Disk::USER_HOME->value)->delete($this->makeRelativeBasePath($file));
    }

    /**
     * Write a file to the base directory.
     *
     * `private` maps to mode 0600 on the local driver, which is what a file holding a credential
     * wants — the default 0644 is readable by every other account on the machine.
     */
    protected function putBaseFile(string $file, string $contents, bool $private = false): void
    {
        Storage::disk(Disk::USER_HOME->value)->put(
            $this->makeRelativeBasePath($file),
            $contents,
            ...($private ? ['private'] : []),
        );
    }

    protected function getBaseFile(string $file): string
    {
        return Storage::disk(Disk::USER_HOME->value)->get($this->makeRelativeBasePath($file));
    }

    protected function makeRelativeBasePath(string $relative = ''): string
    {
        return rtrim(Directory::BASE->value.DIRECTORY_SEPARATOR.$relative, DIRECTORY_SEPARATOR);
    }

    protected function ensureBaseDirectoryExists(): void
    {
        $this->ensureDirectoryExists(Directory::BASE->value, Disk::USER_HOME->value);
    }
}
