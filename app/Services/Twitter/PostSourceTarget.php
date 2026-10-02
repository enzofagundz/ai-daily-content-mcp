<?php

namespace App\Services\Twitter;

use Carbon\CarbonImmutable;

final readonly class PostSourceTarget
{
    public function __construct(
        public string $username,
        public ?CarbonImmutable $since = null,
    ) {}
}
