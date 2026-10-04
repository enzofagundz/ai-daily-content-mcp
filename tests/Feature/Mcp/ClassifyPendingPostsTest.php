<?php

use App\Mcp\Servers\DailyContentServer;
use App\Mcp\Tools\ClassifyPendingPosts;
use App\Models\Post;
use App\Models\Profile;
use App\Services\Classification\ClassificationException;
use App\Services\Classification\ContentClassification;
use App\Services\Classification\PostClassificationInput;
use Illuminate\Testing\Fluent\AssertableJson;

test('classify_pending_posts classifies pending posts up to the limit', function () {
    $oldest = Post::factory()->create(['published_at' => now()->subDays(3)]);
    $middle = Post::factory()->create(['published_at' => now()->subDays(2)]);
    $newest = Post::factory()->create(['published_at' => now()->subDays(1)]);
    $already = Post::factory()->classified()->create();

    $fake = fakeClassifier();

    DailyContentServer::tool(ClassifyPendingPosts::class, ['limit' => 2])
        ->assertOk()
        ->assertStructuredContent([
            'classified' => 2,
            'failed' => 0,
            'remaining' => 1,
            'errors' => [],
        ]);

    expect($fake->calls)->toHaveCount(2)
        ->and($newest->refresh()->classification_status)->toBe('classified')
        ->and($middle->refresh()->classification_status)->toBe('classified')
        ->and($oldest->refresh()->classification_status)->toBe('pending')
        ->and($already->refresh()->classification_status)->toBe('classified');
});

test('classify_pending_posts defaults to twenty posts per call', function () {
    foreach (range(1, 25) as $index) {
        Post::factory()->create(['published_at' => now()->subMinutes($index)]);
    }

    $fake = fakeClassifier();

    DailyContentServer::tool(ClassifyPendingPosts::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->where('classified', 20)
                ->where('failed', 0)
                ->where('remaining', 5)
                ->where('errors', []);
        });

    expect($fake->calls)->toHaveCount(20)
        ->and(Post::query()->where('classification_status', Post::CLASSIFICATION_CLASSIFIED)->count())->toBe(20);
});

test('classify_pending_posts leaves failed posts alone by default', function () {
    Post::factory()->create();
    $failed = Post::factory()->failed()->create();

    $fake = fakeClassifier();

    DailyContentServer::tool(ClassifyPendingPosts::class, [])
        ->assertOk()
        ->assertStructuredContent([
            'classified' => 1,
            'failed' => 0,
            'remaining' => 0,
            'errors' => [],
        ]);

    expect($fake->calls)->toHaveCount(1)
        ->and($failed->refresh()->classification_status)->toBe('failed')
        ->and($failed->classification_error)->toBe('Cloudflare Workers AI returned HTTP 500.');
});

test('classify_pending_posts retries failed posts when retry_failed is set', function () {
    Post::factory()->create();
    $failed = Post::factory()->failed()->create();

    fakeClassifier();

    DailyContentServer::tool(ClassifyPendingPosts::class, ['retry_failed' => true])
        ->assertOk()
        ->assertStructuredContent([
            'classified' => 2,
            'failed' => 0,
            'remaining' => 0,
            'errors' => [],
        ]);

    $failed->refresh();

    expect($failed->classification_status)->toBe('classified')
        ->and($failed->classification_error)->toBeNull();
});

test('classify_pending_posts isolates a per-post failure', function () {
    $broken = Post::factory()->create(['text' => 'broken post']);
    Post::factory()->create(['text' => 'working post']);

    fakeClassifier(callback: function (PostClassificationInput $post): ContentClassification {
        if ($post->text === 'broken post') {
            throw new ClassificationException('Cloudflare Workers AI returned HTTP 500.');
        }

        return classificationSignals();
    });

    DailyContentServer::tool(ClassifyPendingPosts::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($broken) {
            $json->where('classified', 1)
                ->where('failed', 1)
                ->where('remaining', 0)
                ->has('errors', 1)
                ->where('errors.0.post_id', $broken->id)
                ->where('errors.0.message', 'Cloudflare Workers AI returned HTTP 500.');
        });

    expect($broken->refresh()->classification_status)->toBe('failed');
});

test('classify_pending_posts ignores pending posts from removed profiles', function () {
    $removed = Profile::factory()->create(['username' => 'theo']);
    Post::factory()->create(['profile_id' => $removed->id]);
    $removed->delete();

    fakeClassifier();

    DailyContentServer::tool(ClassifyPendingPosts::class, [])
        ->assertOk()
        ->assertStructuredContent([
            'classified' => 0,
            'failed' => 0,
            'remaining' => 0,
            'errors' => [],
        ]);
});

test('classify_pending_posts rejects an invalid limit', function (int $limit) {
    fakeClassifier();

    DailyContentServer::tool(ClassifyPendingPosts::class, ['limit' => $limit])
        ->assertHasErrors();
})->with([0, 101]);
