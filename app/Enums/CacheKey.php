<?php

namespace App\Enums;

use Carbon\CarbonInterval;

enum CacheKey: string
{
    case LATEST_RELEASE = 'latest_release';

    /**
     * How long a cached value stays fresh.
     *
     * Kept beside the key rather than at the call site, so a lifetime cannot drift from the entry it
     * belongs to.
     */
    public function ttl(): CarbonInterval
    {
        return match ($this) {
            self::LATEST_RELEASE => CarbonInterval::minutes(15),
        };
    }
}
