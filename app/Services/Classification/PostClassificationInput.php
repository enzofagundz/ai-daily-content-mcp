<?php

namespace App\Services\Classification;

use Carbon\CarbonImmutable;

final readonly class PostClassificationInput
{
    public function __construct(
        public string $author,
        public string $username,
        public string $text,
        public string $url,
        public CarbonImmutable $publishedAt,
    ) {}
}
