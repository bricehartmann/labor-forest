<?php

use App\Data\GitHubReleaseData;
use App\Exceptions\GitHubReleasesNotFound;
use App\Filament\Widgets\AppVersionWidget;
use App\Services\GitHubService;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\Fakes\ProcessSpy;

beforeEach(function () {
    // mount() always asks for the latest release, so every test has to answer — the widget swallows
    // the failure, and an unmocked service would quietly exercise the failure path instead.
    appVersionGitHubService(appVersionReleaseData());
});

describe('render', function () {
    it('renders the heading and description', function () {
        Livewire::test(AppVersionWidget::class)
            ->assertOk()
            ->assertSee('App Version')
            ->assertSee('Below is the currently installed application version.');
    });

    it('renders the configured application version', function () {
        config()->set('nativephp.version', 'v1.0.0-rc.2');

        Livewire::test(AppVersionWidget::class)
            ->assertOk()
            ->assertSee('v1.0.0-rc.2');
    });

    it('still renders when no application version is configured', function () {
        config()->set('nativephp.version', null);

        Livewire::test(AppVersionWidget::class)
            ->assertOk()
            ->assertSee('App Version')
            ->assertDontSee('v1.0.0-rc.2');
    });
});

describe('release lookup', function () {
    it('stores the tag and page of the latest release', function () {
        appVersionGitHubService(appVersionReleaseData(tag: 'v2.0.0', url: 'https://example.test/releases/v2.0.0'));

        Livewire::test(AppVersionWidget::class)
            ->assertOk()
            ->assertSet('latestReleaseTag', 'v2.0.0')
            ->assertSet('latestReleaseHtmlUrl', 'https://example.test/releases/v2.0.0');
    });

    it('renders without a release when the lookup fails', function () {
        Exceptions::fake();

        appVersionGitHubService(new GitHubReleasesNotFound('https://example.test/releases/latest'));

        Livewire::test(AppVersionWidget::class)
            ->assertOk()
            ->assertSet('latestReleaseTag', null)
            ->assertSet('latestReleaseHtmlUrl', null)
            ->assertDontSee('Upgrade to')
            ->assertDontSee('latest version');

        // rescue(report: false) — a repo without a published release is not worth reporting.
        Exceptions::assertNothingReported();
    });
});

describe('version comparison', function () {
    it('shows a badge when the installed version is the latest release', function () {
        config()->set('nativephp.version', 'v1.2.3');

        appVersionGitHubService(appVersionReleaseData(tag: 'v1.2.3'));

        Livewire::test(AppVersionWidget::class)
            ->assertOk()
            ->assertSee('latest version')
            ->assertDontSee('Upgrade to');
    });

    it('offers the upgrade when a newer release exists', function () {
        config()->set('nativephp.version', 'v1.0.0');

        appVersionGitHubService(appVersionReleaseData(tag: 'v1.2.3'));

        Livewire::test(AppVersionWidget::class)
            ->assertOk()
            ->assertSee('Upgrade to v1.2.3')
            ->assertDontSee('latest version');
    });

    it('claims nothing when the lookup failed and no version is configured', function () {
        config()->set('nativephp.version', null);

        appVersionGitHubService(new GitHubReleasesNotFound('https://example.test/releases/latest'));

        Livewire::test(AppVersionWidget::class)
            ->assertOk()
            ->assertDontSee('latest version');
    });
});

describe('upgrade action', function () {
    it('opens the release page of the latest release', function () {
        config()->set('nativephp.version', 'v1.0.0');

        appVersionGitHubService(appVersionReleaseData(tag: 'v1.2.3', url: 'https://example.test/releases/v1.2.3'));

        $spy = ProcessSpy::install();

        Livewire::test(AppVersionWidget::class)
            ->assertOk()
            ->callAction('upgrade');

        expect($spy->commands)->toBe([
            [['open', 'https://example.test/releases/v1.2.3'], null],
        ]);
    });
});

/**
 * A GitHubService answering getLatestReleaseData() with the given release, or throwing the given
 * exception instead.
 */
function appVersionGitHubService(GitHubReleaseData|Throwable $result): void
{
    test()->mock(GitHubService::class, function (MockInterface $mock) use ($result) {
        $expectation = $mock->shouldReceive('getLatestReleaseData');

        $result instanceof Throwable
            ? $expectation->andThrow($result)
            : $expectation->andReturn($result);
    });
}

/**
 * The release the widget compares the installed version against.
 */
function appVersionReleaseData(
    string $tag = 'v1.2.3',
    string $url = 'https://example.test/releases/v1.2.3',
): GitHubReleaseData {
    return new GitHubReleaseData(html_url: $url, tag_name: $tag);
}
