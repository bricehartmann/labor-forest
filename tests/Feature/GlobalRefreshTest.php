<?php

use App\Enums\BroadcastChannel;
use App\Events\GlobalRefresh;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

it('broadcasts on the channel the desktop window listens to', function () {
    $channels = (new GlobalRefresh)->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(Channel::class)
        ->and($channels[0]->name)->toBe(BroadcastChannel::NATIVEPHP->value);
});

it('broadcasts immediately rather than through the queue', function () {
    // an MCP tool call is served by a short-lived request with no worker behind it, so a queued
    // broadcast would never reach the window the refresh is meant for
    expect(new GlobalRefresh)->toBeInstanceOf(ShouldBroadcastNow::class);
});
