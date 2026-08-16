<?php

use App\Filament\Pages\Dashboard;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('user_home');

    // AppVersionWidget renders with the dashboard and asks GitHub for the latest release. Answering
    // it here keeps the page under test off the network and out of its own failure path.
    Http::fake([config('app.latest_release_url') => Http::response([
        'html_url' => 'https://example.test/releases/v1.2.3',
        'tag_name' => 'v1.2.3',
    ])]);
});

describe('the query string notification', function () {
    it('shows an error as a persistent danger notification', function () {
        dashboardWithQuery(['error' => 'Path does not exist.'])
            ->assertOk()
            ->assertNotified(
                Notification::make()
                    ->danger()
                    ->title('Path does not exist.')
                    ->icon(Heroicon::XCircle)
                    ->persistent()
            );
    });

    it('shows a success as a persistent success notification', function () {
        dashboardWithQuery(['success' => 'Workflow [up] is valid.'])
            ->assertOk()
            ->assertNotified(
                Notification::make()
                    ->success()
                    ->title('Workflow [up] is valid.')
                    ->icon(Heroicon::CheckCircle)
                    ->persistent()
            );
    });

    it('renders the body of a multi-line error across lines', function () {
        dashboardWithQuery([
            'error' => 'Workflow [up] is invalid',
            'body' => "/tmp/repo-feature/.laborforest/workflows/up.yaml\n• The steps field is required.",
        ])
            ->assertOk()
            ->assertNotified(
                Notification::make()
                    ->danger()
                    ->title('Workflow [up] is invalid')
                    ->body(new HtmlString("/tmp/repo-feature/.laborforest/workflows/up.yaml<br />\n• The steps field is required."))
                    ->icon(Heroicon::XCircle)
                    ->persistent()
            );
    });

    it('escapes a body so a workflow file cannot inject markup', function () {
        dashboardWithQuery([
            'error' => 'Workflow [up] is invalid',
            'body' => '<script>alert(1)</script>',
        ])
            ->assertOk()
            ->assertNotified(
                Notification::make()
                    ->danger()
                    ->title('Workflow [up] is invalid')
                    ->body(new HtmlString('&lt;script&gt;alert(1)&lt;/script&gt;'))
                    ->icon(Heroicon::XCircle)
                    ->persistent()
            );
    });

    it('ignores a blank parameter', function () {
        dashboardWithQuery(['error' => '', 'success' => ''])
            ->assertOk()
            ->assertNotNotified()
            ->assertJs(componentQueryStringClearingJs());
    });

    it('shows nothing when no parameter is given', function () {
        dashboardWithQuery([])
            ->assertOk()
            ->assertNotNotified()
            ->assertNoJs();
    });

    it('clears the parameters from the address bar so a reload cannot repeat the notification', function () {
        dashboardWithQuery([
            'error' => 'Workflow [up] is invalid',
            'body' => 'The steps field is required.',
        ])
            ->assertOk()
            ->assertJs(componentQueryStringClearingJs());
    });
});

/**
 * Mount the dashboard as the CLI drain paths land on it — with the message on the query string.
 *
 * @param  array<string, string>  $query
 */
function dashboardWithQuery(array $query): Testable
{
    return Livewire::withQueryParams($query)->test(Dashboard::class);
}
