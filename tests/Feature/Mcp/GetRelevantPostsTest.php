<?php

use App\Mcp\Servers\DailyContentServer;
use App\Mcp\Tools\GetRelevantPosts;
use App\Models\Post;
use Illuminate\Testing\Fluent\AssertableJson;

test('get_relevant_posts returns only relevant classified posts, newest first', function () {
    $oldest = Post::factory()->classified()->create(['published_at' => now()->subDays(2)]);
    $newest = Post::factory()->classified()->create(['published_at' => now()->subDay()]);

    Post::factory()->classified()->create(['classification_relevant' => false]);
    Post::factory()->create();
    Post::factory()->create([
        'classification_status' => Post::CLASSIFICATION_FAILED,
        'classification_relevant' => true,
    ]);

    DailyContentServer::tool(GetRelevantPosts::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($newest, $oldest) {
            $json->has('posts', 2)
                ->where('posts.0.id', $newest->id)
                ->where('posts.0.author', $newest->author)
                ->where('posts.0.username', $newest->profile->username)
                ->where('posts.0.text', $newest->text)
                ->where('posts.0.url', $newest->url)
                ->where('posts.0.classification.status', 'classified')
                ->where('posts.0.classification.relevant', true)
                ->where('posts.0.classification.score', 0.9)
                ->where('posts.0.classification.category', 'developer_tools')
                ->where('posts.0.classification.content_value_score', 3.2)
                ->where('posts.0.classification.adaptability_score', 3.5)
                ->where('posts.0.classification.fits_profile', true)
                ->where('posts.0.classification.requires_missing_media', false)
                ->where('posts.1.id', $oldest->id);
        });
});

test('get_relevant_posts defaults to ten posts and honours the limit', function () {
    foreach (range(1, 12) as $index) {
        Post::factory()->classified()->create(['published_at' => now()->subMinutes($index)]);
    }

    DailyContentServer::tool(GetRelevantPosts::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('posts', 10);
        });

    DailyContentServer::tool(GetRelevantPosts::class, ['limit' => 20])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('posts', 12);
        });
});

test('get_relevant_posts never classifies or reclassifies posts', function () {
    $fake = fakeClassifier();

    Post::factory()->create();
    Post::factory()->failed()->create();

    DailyContentServer::tool(GetRelevantPosts::class, [])->assertOk();

    expect($fake->calls)->toBe([]);
});

test('get_relevant_posts rejects an invalid limit', function (int $limit) {
    DailyContentServer::tool(GetRelevantPosts::class, ['limit' => $limit])
        ->assertHasErrors();
})->with([0, 101]);
