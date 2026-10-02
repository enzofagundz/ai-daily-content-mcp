<?php

namespace App\Services\Twitter;

interface PostSource
{
    /**
     * Fetch recent posts for the given targets.
     *
     * @param  list<PostSourceTarget>  $targets
     * @param  int  $limit  Maximum number of posts fetched per profile.
     */
    public function fetch(array $targets, int $limit): PostFetchResult;
}
