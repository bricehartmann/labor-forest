<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class GitHubReleaseData extends Data
{
    public function __construct(
        public string $html_url,
        public string $tag_name,
    ) {}
}
