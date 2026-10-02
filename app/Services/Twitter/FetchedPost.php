<?php

namespace App\Services\Twitter;

use Carbon\CarbonImmutable;

final readonly class FetchedPost
{
    public function __construct(
        public string $externalId,
        public string $username,
        public string $author,
        public string $text,
        public string $url,
        public CarbonImmutable $publishedAt,
    ) {}
}
