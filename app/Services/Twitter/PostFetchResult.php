<?php

namespace App\Services\Twitter;

final readonly class PostFetchResult
{
    /**
     * @param  list<FetchedPost>  $posts
     * @param  array<string, string>  $errors  Error message keyed by profile username.
     */
    public function __construct(
        public array $posts,
        public array $errors,
    ) {}

    /**
     * Build a result where every target failed with the same message.
     *
     * @param  list<PostSourceTarget>  $targets
     */
    public static function failed(array $targets, string $message): self
    {
        return new self(
            [],
            array_fill_keys(array_map(fn (PostSourceTarget $target): string => $target->username, $targets), $message),
        );
    }
}
