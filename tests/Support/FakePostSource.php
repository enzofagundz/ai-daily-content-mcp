<?php

namespace Tests\Support;

use App\Services\Twitter\PostFetchResult;
use App\Services\Twitter\PostSource;
use App\Services\Twitter\PostSourceTarget;

class FakePostSource implements PostSource
{
    /**
     * @var list<array{targets: list<PostSourceTarget>, limit: int}>
     */
    public array $calls = [];

    public function __construct(
        private PostFetchResult $result,
    ) {}

    public function fetch(array $targets, int $limit): PostFetchResult
    {
        $this->calls[] = ['targets' => $targets, 'limit' => $limit];

        return $this->result;
    }
}
