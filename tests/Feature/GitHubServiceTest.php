<?php

use App\Data\GitHubReleaseData;
use App\Exceptions\GitHubReleaseParsingFailed;
use App\Exceptions\GitHubReleasesNotFound;
use App\Services\GitHubService;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->github = new GitHubService;

    $this->url = config('app.latest_release_url');
});

describe('getLatestReleaseData', function () {
    it('returns the tag and page of the latest release', function () {
        Http::fake([$this->url => Http::response([githubReleasePayload()])]);

        $release = $this->github->getLatestReleaseData();

        expect($release)->toBeInstanceOf(GitHubReleaseData::class)
            ->and($release->tag_name)->toBe('v1.2.3')
            ->and($release->html_url)->toBe('https://github.com/bricehartmann/labor-forest/releases/tag/v1.2.3');

        Http::assertSent(fn (Request $request) => $request->url() === $this->url);
    });

    it('ignores the rest of the release payload', function () {
        Http::fake([$this->url => Http::response([githubReleasePayload(extra: [
            'id' => 1234567,
            'name' => 'LaborForest v1.2.3',
            'assets' => [['name' => 'LaborForest.dmg', 'size' => 123]],
            'body' => "## What's changed\n- things",
        ])])]);

        expect($this->github->getLatestReleaseData()->tag_name)->toBe('v1.2.3');
    });

    it('reads the endpoint from configuration', function () {
        config()->set('app.latest_release_url', 'https://example.test/releases');

        Http::fake(['https://example.test/releases' => Http::response([githubReleasePayload()])]);

        $this->github->getLatestReleaseData();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://example.test/releases');
    });

    it('prefers the newest stable release', function () {
        Http::fake([$this->url => Http::response([
            githubReleasePayload(tagName: 'v2.0.0-rc.1', prerelease: true, publishedAt: '2026-03-01T00:00:00Z'),
            githubReleasePayload(tagName: 'v1.9.0', publishedAt: '2026-02-01T00:00:00Z'),
            githubReleasePayload(tagName: 'v1.8.0', publishedAt: '2026-01-01T00:00:00Z'),
        ])]);

        expect($this->github->getLatestReleaseData()->tag_name)->toBe('v1.9.0');
    });

    it('falls back to the newest prerelease when no stable release exists', function () {
        Http::fake([$this->url => Http::response([
            githubReleasePayload(tagName: 'v1.0.0-rc.7', prerelease: true, publishedAt: '2026-08-21T01:07:02Z'),
            githubReleasePayload(tagName: 'v1.0.0-rc.6', prerelease: true, publishedAt: '2026-08-20T22:56:50Z'),
        ])]);

        $release = $this->github->getLatestReleaseData();

        expect($release->tag_name)->toBe('v1.0.0-rc.7')
            ->and($release->html_url)->toBe('https://github.com/bricehartmann/labor-forest/releases/tag/v1.0.0-rc.7');
    });

    it('never offers a draft release', function () {
        Http::fake([$this->url => Http::response([
            githubReleasePayload(tagName: 'v2.0.0', draft: true, publishedAt: '2026-03-01T00:00:00Z'),
            githubReleasePayload(tagName: 'v1.9.0', publishedAt: '2026-02-01T00:00:00Z'),
        ])]);

        expect($this->github->getLatestReleaseData()->tag_name)->toBe('v1.9.0');
    });

    it('orders releases by their publication date, not by their position', function () {
        Http::fake([$this->url => Http::response([
            githubReleasePayload(tagName: 'v1.8.0', publishedAt: '2026-01-01T00:00:00Z'),
            githubReleasePayload(tagName: 'v1.9.0', publishedAt: '2026-02-01T00:00:00Z'),
        ])]);

        expect($this->github->getLatestReleaseData()->tag_name)->toBe('v1.9.0');
    });

    it('throws when the repository has published nothing', function () {
        Http::fake([$this->url => Http::response([])]);

        expect(fn () => $this->github->getLatestReleaseData())
            ->toThrow(GitHubReleasesNotFound::class, "No releases found at URL: {$this->url}");
    });

    it('throws when every release is a draft', function () {
        Http::fake([$this->url => Http::response([githubReleasePayload(draft: true)])]);

        expect(fn () => $this->github->getLatestReleaseData())
            ->toThrow(GitHubReleasesNotFound::class, "No releases found at URL: {$this->url}");
    });

    it('throws when the repository has no releases', function () {
        Http::fake([$this->url => Http::response('', 404)]);

        expect(fn () => $this->github->getLatestReleaseData())
            ->toThrow(GitHubReleasesNotFound::class, "No releases found at URL: {$this->url}");
    });

    it('throws when GitHub answers with a server error', function () {
        Http::fake([$this->url => Http::response('', 500)]);

        expect(fn () => $this->github->getLatestReleaseData())
            ->toThrow(GitHubReleasesNotFound::class, "No releases found at URL: {$this->url}");
    });

    it('throws with the payload when a required field is missing', function () {
        Http::fake([$this->url => Http::response([['html_url' => 'https://example.test/releases/tag/v1.2.3']])]);

        expect(fn () => $this->github->getLatestReleaseData())
            ->toThrow(
                GitHubReleaseParsingFailed::class,
                'Failed to parse GitHub release: [{"html_url":"https:\/\/example.test\/releases\/tag\/v1.2.3"}]',
            );
    });

    it('keeps the validation failure as the previous exception', function () {
        Http::fake([$this->url => Http::response([['html_url' => 'https://example.test/releases/tag/v1.2.3']])]);

        try {
            $this->github->getLatestReleaseData();
        } catch (GitHubReleaseParsingFailed $th) {
            expect($th->getPrevious())->not->toBeNull();

            return;
        }

        $this->fail('GitHubReleaseParsingFailed was never thrown.');
    });

    it('throws with the payload when the body is a single release rather than a list', function () {
        Http::fake([$this->url => Http::response(githubReleasePayload(
            htmlUrl: 'https://example.test/releases/tag/v1.2.3',
            publishedAt: '2026-01-01T00:00:00Z',
        ))]);

        expect(fn () => $this->github->getLatestReleaseData())
            ->toThrow(GitHubReleaseParsingFailed::class);
    });

    it('throws with the payload when the body is not JSON', function () {
        Http::fake([$this->url => Http::response('<html>nope</html>')]);

        expect(fn () => $this->github->getLatestReleaseData())
            ->toThrow(GitHubReleaseParsingFailed::class, 'Failed to parse GitHub release: <html>nope</html>');
    });

    it('lets a connection failure through untouched', function () {
        Http::fake([$this->url => fn () => throw new ConnectionException('Could not resolve host.')]);

        expect(fn () => $this->github->getLatestReleaseData())
            ->toThrow(ConnectionException::class, 'Could not resolve host.');
    });
});

describe('caching', function () {
    afterEach(function () {
        Carbon::setTestNow();
    });

    it('answers a second call from the cache', function () {
        Http::fake([$this->url => Http::response([githubReleasePayload()])]);

        $first = $this->github->getLatestReleaseData();
        $second = $this->github->getLatestReleaseData();

        expect($second->tag_name)->toBe($first->tag_name)
            ->and($second->html_url)->toBe($first->html_url);

        Http::assertSentCount(1);
    });

    it('hands back a release object from the cache', function () {
        Http::fake([$this->url => Http::response([githubReleasePayload()])]);

        $this->github->getLatestReleaseData();

        expect($this->github->getLatestReleaseData())->toBeInstanceOf(GitHubReleaseData::class);

        Http::assertSentCount(1);
    });

    it('is still cached just before the window closes', function () {
        Http::fake([$this->url => Http::response([githubReleasePayload()])]);

        $this->github->getLatestReleaseData();
        $this->travel(14)->minutes();
        $this->github->getLatestReleaseData();

        Http::assertSentCount(1);
    });

    it('asks GitHub again once the window has passed', function () {
        Http::fake([$this->url => Http::response([githubReleasePayload()])]);

        $this->github->getLatestReleaseData();
        $this->travel(16)->minutes();
        $this->github->getLatestReleaseData();

        Http::assertSentCount(2);
    });

    it('never caches a failed lookup', function () {
        Http::fakeSequence()
            ->push('', 404)
            ->push([githubReleasePayload()]);

        expect(fn () => $this->github->getLatestReleaseData())->toThrow(GitHubReleasesNotFound::class)
            ->and($this->github->getLatestReleaseData()->tag_name)->toBe('v1.2.3');

        Http::assertSentCount(2);
    });

    it('asks GitHub again when the cache is bypassed', function () {
        Http::fakeSequence()
            ->push([githubReleasePayload()])
            ->push([githubReleasePayload(tagName: 'v2.0.0')]);

        expect($this->github->getLatestReleaseData()->tag_name)->toBe('v1.2.3')
            ->and($this->github->getLatestReleaseData(bypassCache: true)->tag_name)->toBe('v2.0.0');

        Http::assertSentCount(2);
    });

    it('caches what a bypassed call fetched', function () {
        Http::fakeSequence()
            ->push([githubReleasePayload()])
            ->push([githubReleasePayload(tagName: 'v2.0.0')]);

        $this->github->getLatestReleaseData();
        $this->github->getLatestReleaseData(bypassCache: true);

        expect($this->github->getLatestReleaseData()->tag_name)->toBe('v2.0.0');

        Http::assertSentCount(2);
    });
});

describe('latest release url', function () {
    it('points at the release list of the application repository', function () {
        expect(config('app.latest_release_url'))
            ->toBe('https://api.github.com/repos/bricehartmann/labor-forest/releases?per_page=100');
    });
});

/**
 * One entry of the GitHub release list: the two fields the application reads, the three the service
 * selects on, plus anything a test wants to bury them in.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function githubReleasePayload(
    string $tagName = 'v1.2.3',
    ?string $htmlUrl = null,
    bool $prerelease = false,
    bool $draft = false,
    string $publishedAt = '2026-01-01T00:00:00Z',
    array $extra = [],
): array {
    return [
        ...$extra,
        'html_url' => $htmlUrl ?? "https://github.com/bricehartmann/labor-forest/releases/tag/{$tagName}",
        'tag_name' => $tagName,
        'prerelease' => $prerelease,
        'draft' => $draft,
        'published_at' => $publishedAt,
    ];
}
