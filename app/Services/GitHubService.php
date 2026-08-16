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
     */
    public function getLatestReleaseData(bool $bypassCache = false): GitHubReleaseData
    {
        if ($bypassCache) {
            Cache::forget(CacheKey::LATEST_RELEASE->value);
        }

        // The array is cached rather than the object: the cache outlives an app upgrade, and a stale
        // object of a class that has since gained a property would blow up on property access, past
        // every caller's rescue(). A stale array fails inside from(), where the caller still has it.
        return GitHubReleaseData::from(Cache::remember(
            CacheKey::LATEST_RELEASE->value,
            CacheKey::LATEST_RELEASE->ttl(),
            fn () => $this->fetchLatestRelease()->toArray(),
        ));
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
