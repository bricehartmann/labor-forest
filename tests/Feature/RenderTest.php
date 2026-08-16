<?php

use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // The dashboard renders AppVersionWidget, which asks GitHub for the latest release.
    Http::fake([config('app.latest_release_url') => Http::response([
        'html_url' => 'https://example.test/releases/v1.2.3',
        'tag_name' => 'v1.2.3',
    ])]);
});

test('the application returns a successful response', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});
