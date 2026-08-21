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

    /**
     * The release the dashboard should offer, picked out of the repository's release list.
     *
     * The endpoint is the *list* rather than /releases/latest, which answers 404 while every
     * published release is a prerelease — as it was for the whole v1.0.0-rc series, leaving the
     * dashboard with nothing to compare against and no visible reason why.
     *
     * Newest stable wins; a prerelease is only offered when the repository has published no stable
     * release at all, so an RC stops being advertised the moment a GA release exists. Ordering is
     * decided here by published_at rather than taken from the response, whose order is created_at
     * descending — the date of the tagged *commit*, which a tag cut from an older commit gets wrong.
     */
    private function fetchLatestRelease(): GitHubReleaseData
    {
        $response = Http::get(config('app.latest_release_url'));

        if (! $response->successful()) {
            throw new GitHubReleasesNotFound(config('app.latest_release_url'));
        }

        $payload = $response->json();

        if (! is_array($payload) || ! array_is_list($payload)) {
            throw new GitHubReleaseParsingFailed($response->body());
        }

        $releases = collect($payload)
            ->filter(fn (mixed $release) => is_array($release) && ! ($release['draft'] ?? false))
            ->sortByDesc(fn (array $release) => $release['published_at'] ?? '')
            ->values();

        $release = $releases->first(fn (array $release) => ! ($release['prerelease'] ?? false))
            ?? $releases->first();

        if ($release === null) {
            throw new GitHubReleasesNotFound(config('app.latest_release_url'));
        }

        try {
            $data = GitHubReleaseData::validateAndCreate($release);
        } catch (Throwable $th) {
            throw new GitHubReleaseParsingFailed($response->body(), $th);
        }

        return $data;
    }
}
