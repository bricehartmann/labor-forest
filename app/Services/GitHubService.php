<?php

namespace App\Services;

use App\Data\GitHubReleaseData;
use App\Enums\CacheKey;
use App\Exceptions\GitHubReleaseParsingFailed;
use App\Exceptions\GitHubReleasesNotFound;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class GitHubService
{
    /**
     * The newest published release of the application.
     *
     * The answer changes a few times a month at most, while the dashboard asks on every render, so it
     * is held for CacheKey::LATEST_RELEASE->ttl(). Failures escape Cache::remember() and are therefore
     * never cached — the next call retries. Pass $bypassCache to force a fresh check.
     *
     * The object is cached rather than an array of it because NativeAppServiceProvider::boot()
     * flushes the cache on every launch: no entry outlives the version that wrote it, so a stale
     * GitHubReleaseData of a class that has since gained a property can never be read back.
     */
    public function getLatestReleaseData(bool $bypassCache = false): GitHubReleaseData
    {
        if ($bypassCache) {
            Cache::forget(CacheKey::LATEST_RELEASE->value);
        }

        return Cache::remember(
            CacheKey::LATEST_RELEASE->value,
            CacheKey::LATEST_RELEASE->ttl(),
            fn () => $this->fetchLatestRelease(),
        );
    }

    private function fetchLatestRelease(): GitHubReleaseData
    {
        $response = Http::get(config('app.latest_release_url'));

        if (! $response->successful()) {
            throw new GitHubReleasesNotFound(config('app.latest_release_url'));
        }

        try {
            $data = GitHubReleaseData::validateAndCreate($response->json());
        } catch (Throwable $th) {
            throw new GitHubReleaseParsingFailed($response->body(), $th);
        }

        return $data;
    }
}
