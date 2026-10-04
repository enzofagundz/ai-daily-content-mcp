<?php

use App\Mcp\Servers\DailyContentServer;
use App\Mcp\Tools\GetNewPosts;
use App\Mcp\Tools\RemoveProfile;
use App\Models\Post;
use App\Models\Profile;
use App\Services\Classification\ClassificationException;
use App\Services\Classification\ContentClassification;
use App\Services\Classification\PostClassificationInput;
use App\Services\Twitter\PostFetchResult;
use Carbon\CarbonImmutable;
use Illuminate\Testing\Fluent\AssertableJson;

use function Pest\Laravel\travelTo;

test('get_new_posts persists fetched posts and returns them newest first, marked as presented', function () {
    travelTo('2026-10-03 07:00:00');

    Profile::factory()->create(['username' => 'theo']);

    fakeSource(new PostFetchResult([
        fetchedPost('1', 'theo', '2026-10-01T08:00:00Z'),
        fetchedPost('2', 'theo', '2026-10-02T08:00:00Z'),
    ], []));

    fakeClassifier();

    DailyContentServer::tool(GetNewPosts::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('posts', 2)
                ->where('posts.0.author', 'Theo')
                ->where('posts.0.username', 'theo')
                ->where('posts.0.text', 'post text')
                ->where('posts.0.url', 'https://x.com/theo/status/2')
                ->where('posts.0.published_at', '2026-10-02T08:00:00+00:00')
                ->where('posts.1.url', 'https://x.com/theo/status/1')
                ->where('errors', [])
                ->where('classification.classified', 2)
                ->where('classification.failed', 0)
                ->where('classification.errors', []);
        });

    $this->assertDatabaseCount('posts', 2);
    $this->assertDatabaseHas('posts', ['external_id' => '1', 'author' => 'Theo']);
    $this->assertDatabaseMissing('posts', ['presented_at' => null]);
    $this->assertDatabaseMissing('posts', ['classification_status' => 'pending']);
});

test('get_new_posts does not return a post twice', function () {
    travelTo('2026-10-02 12:00:00');

    Profile::factory()->create(['username' => 'theo']);

    fakeSource(new PostFetchResult([
        fetchedPost('1', 'theo', '2026-10-01T08:00:00Z'),
    ], []));

    fakeClassifier();

    DailyContentServer::tool(GetNewPosts::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('posts', 1)
                ->where('errors', [])
                ->where('classification.classified', 1)
                ->where('classification.failed', 0);
        });

    DailyContentServer::tool(GetNewPosts::class, [])
        ->assertOk()
        ->assertStructuredContent([
            'posts' => [],
            'errors' => [],
            'classification' => ['classified' => 0, 'failed' => 0, 'errors' => []],
        ]);

    $this->assertDatabaseCount('posts', 1);
});

test('get_new_posts keeps the excess pending and returns it on later calls', function () {
    travelTo('2026-10-02 12:00:00');

    Profile::factory()->create(['username' => 'theo']);

    $posts = [];

    foreach (range(1, 30) as $index) {
        $posts[] = fetchedPost(
            (string) $index,
            'theo',
            CarbonImmutable::parse('2026-10-01T00:00:00Z')->addMinutes($index)->toIso8601String(),
        );
    }

    fakeSource(new PostFetchResult($posts, []));

    fakeClassifier();

    DailyContentServer::tool(GetNewPosts::class, ['limit' => 20])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('posts', 20)
                ->where('posts.0.url', 'https://x.com/theo/status/30')
                ->where('posts.19.url', 'https://x.com/theo/status/11')
                ->where('errors', [])
                ->where('classification.classified', 30)
                ->where('classification.failed', 0);
        });

    $this->assertDatabaseCount('posts', 30);

    DailyContentServer::tool(GetNewPosts::class, ['limit' => 20])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('posts', 10)
                ->where('posts.0.url', 'https://x.com/theo/status/10')
                ->where('posts.9.url', 'https://x.com/theo/status/1')
                ->where('errors', [])
                ->where('classification.classified', 0)
                ->where('classification.failed', 0);
        });

    DailyContentServer::tool(GetNewPosts::class, ['limit' => 20])
        ->assertOk()
        ->assertStructuredContent([
            'posts' => [],
            'errors' => [],
            'classification' => ['classified' => 0, 'failed' => 0, 'errors' => []],
        ]);
});

test('get_new_posts does not present posts older than the recency window', function () {
    travelTo('2026-10-04 12:00:00');

    Profile::factory()->create(['username' => 'theo']);

    fakeSource(new PostFetchResult([
        fetchedPost('1', 'theo', '2026-09-20T08:00:00Z'),
    ], []));

    fakeClassifier();

    DailyContentServer::tool(GetNewPosts::class, [])
        ->assertOk()
        ->assertStructuredContent([
            'posts' => [],
            'errors' => [],
            'classification' => ['classified' => 1, 'failed' => 0, 'errors' => []],
        ]);

    $this->assertDatabaseHas('posts', ['external_id' => '1', 'presented_at' => null]);
});

test('get_new_posts uses the configured recency window', function () {
    travelTo('2026-10-04 12:00:00');
    config(['content.window_hours' => 1]);

    Profile::factory()->create(['username' => 'theo']);

    fakeSource(new PostFetchResult([
        fetchedPost('1', 'theo', '2026-10-04T11:30:00Z'),
        fetchedPost('2', 'theo', '2026-10-04T09:00:00Z'),
    ], []));

    fakeClassifier();

    DailyContentServer::tool(GetNewPosts::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('posts', 1)
                ->where('posts.0.url', 'https://x.com/theo/status/1')
                ->where('errors', [])
                ->where('classification.classified', 2)
                ->where('classification.failed', 0)
                ->where('classification.errors', []);
        });

    $this->assertDatabaseHas('posts', ['external_id' => '2', 'presented_at' => null]);
    $this->assertDatabaseMissing('posts', ['external_id' => '1', 'presented_at' => null]);
});

test('get_new_posts returns the classification of each presented post', function () {
    travelTo('2026-10-04 12:00:00');

    Profile::factory()->create(['username' => 'theo']);

    fakeSource(new PostFetchResult([
        fetchedPost('1', 'theo', '2026-10-04T11:00:00Z'),
    ], []));

    fakeClassifier(classificationSignals(contentValueScore: 2.5, adaptabilityScore: 2.5));

    DailyContentServer::tool(GetNewPosts::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('posts', 1)
                ->where('posts.0.classification.status', 'classified')
                ->where('posts.0.classification.relevant', true)
                ->where('posts.0.classification.score', 0.9)
                ->where('posts.0.classification.category', 'developer_tools')
                ->where('posts.0.classification.content_value_score', 2.5)
                ->where('posts.0.classification.adaptability_score', 2.5)
                ->where('posts.0.classification.fits_profile', true)
                ->where('posts.0.classification.requires_missing_media', false)
                ->where('posts.0.classification.classified_at', '2026-10-04T12:00:00+00:00')
                ->where('errors', [])
                ->where('classification.classified', 1)
                ->where('classification.failed', 0)
                ->where('classification.errors', []);
        });
});

test('get_new_posts returns the classification of a post presented on a later call', function () {
    travelTo('2026-10-04 12:00:00');

    Profile::factory()->create(['username' => 'theo']);

    fakeSource(new PostFetchResult([
        fetchedPost('1', 'theo', '2026-10-04T10:00:00Z'),
        fetchedPost('2', 'theo', '2026-10-04T11:00:00Z'),
    ], []));

    fakeClassifier();

    DailyContentServer::tool(GetNewPosts::class, ['limit' => 1])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('posts', 1)
                ->where('posts.0.url', 'https://x.com/theo/status/2')
                ->where('posts.0.classification.status', 'classified')
                ->where('errors', [])
                ->where('classification.classified', 2)
                ->where('classification.failed', 0)
                ->where('classification.errors', []);
        });

    DailyContentServer::tool(GetNewPosts::class, ['limit' => 1])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('posts', 1)
                ->where('posts.0.url', 'https://x.com/theo/status/1')
                ->where('posts.0.classification.status', 'classified')
                ->where('posts.0.classification.relevant', true)
                ->where('errors', [])
                ->where('classification.classified', 0)
                ->where('classification.failed', 0)
                ->where('classification.errors', []);
        });
});

test('get_new_posts sends the newest known post per profile as since and caps the scrape', function () {
    $theo = Profile::factory()->create(['username' => 'theo']);
    Profile::factory()->create(['username' => 'simonw']);

    Post::factory()->create([
        'profile_id' => $theo->id,
        'external_id' => 'old-1',
        'published_at' => '2026-09-30T10:00:00Z',
    ]);

    Post::factory()->create([
        'profile_id' => $theo->id,
        'external_id' => 'old-2',
        'published_at' => '2026-10-01T10:00:00Z',
    ]);

    $fake = fakeSource(new PostFetchResult([], []));

    DailyContentServer::tool(GetNewPosts::class, [])->assertOk();

    $targets = collect($fake->calls[0]['targets'])->keyBy(fn ($target): string => $target->username);

    expect($fake->calls[0]['limit'])->toBe(60)
        ->and($targets['theo']->since?->toIso8601String())->toBe('2026-10-01T10:00:00+00:00')
        ->and($targets['simonw']->since)->toBeNull();
});

test('get_new_posts rejects an invalid limit', function (int $limit) {
    Profile::factory()->create(['username' => 'theo']);
    fakeSource(new PostFetchResult([], []));

    DailyContentServer::tool(GetNewPosts::class, ['limit' => $limit])
        ->assertHasErrors();
})->with([0, 101]);

test('get_new_posts reports partial profile failures without failing the call', function () {
    travelTo('2026-10-02 12:00:00');

    Profile::factory()->create(['username' => 'theo']);
    Profile::factory()->create(['username' => 'simonw']);

    fakeSource(new PostFetchResult([
        fetchedPost('1', 'theo', '2026-10-01T08:00:00Z'),
    ], ['simonw' => 'profile @simonw was not found on X']));

    fakeClassifier();

    DailyContentServer::tool(GetNewPosts::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('posts', 1)
                ->has('errors', 1)
                ->where('errors.0.username', 'simonw')
                ->where('errors.0.message', 'profile @simonw was not found on X')
                ->where('classification.classified', 1)
                ->where('classification.failed', 0);
        });
});

test('get_new_posts fails when every profile fails', function () {
    Profile::factory()->create(['username' => 'theo']);

    fakeSource(new PostFetchResult([], ['theo' => 'boom']));

    DailyContentServer::tool(GetNewPosts::class, [])
        ->assertHasErrors(['boom']);
});

test('get_new_posts preserves the posts of removed profiles', function () {
    $profile = Profile::factory()->create(['username' => 'theo']);
    Post::factory()->count(2)->create(['profile_id' => $profile->id]);

    DailyContentServer::tool(RemoveProfile::class, ['username' => 'theo'])->assertOk();

    $fake = fakeSource(new PostFetchResult([], []));

    DailyContentServer::tool(GetNewPosts::class, [])
        ->assertOk()
        ->assertSee('No profiles are being monitored');

    expect($fake->calls)->toBe([]);
    $this->assertDatabaseCount('posts', 2);
});

test('get_new_posts classifies only the newly collected posts', function () {
    travelTo('2026-10-03 07:00:00');

    $profile = Profile::factory()->create(['username' => 'theo']);

    Post::factory()->create([
        'profile_id' => $profile->id,
        'external_id' => '1',
        'text' => 'old post',
        'published_at' => '2026-10-01T08:00:00Z',
    ]);

    fakeSource(new PostFetchResult([
        fetchedPost('1', 'theo', '2026-10-01T08:00:00Z', 'old post'),
        fetchedPost('2', 'theo', '2026-10-02T08:00:00Z', 'new post'),
    ], []));

    $classifier = fakeClassifier();

    DailyContentServer::tool(GetNewPosts::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('posts', 2)
                ->where('errors', [])
                ->where('classification.classified', 1)
                ->where('classification.failed', 0)
                ->where('classification.errors', []);
        });

    expect($classifier->calls)->toHaveCount(1)
        ->and($classifier->calls[0]->text)->toBe('new post');

    $this->assertDatabaseHas('posts', ['external_id' => '1', 'classification_status' => Post::CLASSIFICATION_PENDING]);
    $this->assertDatabaseHas('posts', ['external_id' => '2', 'classification_status' => Post::CLASSIFICATION_CLASSIFIED]);
});

test('get_new_posts classifies posts beyond the presentation limit', function () {
    travelTo('2026-10-02 12:00:00');

    Profile::factory()->create(['username' => 'theo']);

    $posts = [];

    foreach (range(1, 30) as $index) {
        $posts[] = fetchedPost(
            (string) $index,
            'theo',
            CarbonImmutable::parse('2026-10-01T00:00:00Z')->addMinutes($index)->toIso8601String(),
        );
    }

    fakeSource(new PostFetchResult($posts, []));

    $classifier = fakeClassifier();

    DailyContentServer::tool(GetNewPosts::class, ['limit' => 20])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('posts', 20)
                ->where('errors', [])
                ->where('classification.classified', 30)
                ->where('classification.failed', 0);
        });

    expect($classifier->calls)->toHaveCount(30)
        ->and(Post::query()->whereNull('presented_at')->count())->toBe(10)
        ->and(Post::query()->where('classification_status', Post::CLASSIFICATION_CLASSIFIED)->count())->toBe(30);
});

test('a classification failure does not fail the collection', function () {
    travelTo('2026-10-03 07:00:00');

    Profile::factory()->create(['username' => 'theo']);

    fakeSource(new PostFetchResult([
        fetchedPost('1', 'theo', '2026-10-01T08:00:00Z', 'broken post'),
        fetchedPost('2', 'theo', '2026-10-02T08:00:00Z', 'working post'),
    ], []));

    fakeClassifier(callback: function (PostClassificationInput $post): ContentClassification {
        if ($post->text === 'broken post') {
            throw new ClassificationException('Cloudflare Workers AI returned HTTP 500.');
        }

        return classificationSignals();
    });

    DailyContentServer::tool(GetNewPosts::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('posts', 2)
                ->where('posts.0.classification.status', 'classified')
                ->where('posts.0.classification.relevant', true)
                ->where('posts.1.classification.status', 'failed')
                ->where('posts.1.classification.relevant', null)
                ->where('errors', [])
                ->where('classification.classified', 1)
                ->where('classification.failed', 1)
                ->has('classification.errors', 1)
                ->where('classification.errors.0.message', 'Cloudflare Workers AI returned HTTP 500.');
        });

    $this->assertDatabaseHas('posts', ['external_id' => '1', 'classification_status' => Post::CLASSIFICATION_FAILED]);
    $this->assertDatabaseHas('posts', ['external_id' => '2', 'classification_status' => Post::CLASSIFICATION_CLASSIFIED]);
    $this->assertDatabaseMissing('posts', ['presented_at' => null]);
});

test('get_new_posts ignores pending posts from removed profiles', function () {
    $removed = Profile::factory()->create(['username' => 'theo']);
    Post::factory()->create(['profile_id' => $removed->id, 'presented_at' => null]);
    $removed->delete();

    Profile::factory()->create(['username' => 'simonw']);

    fakeSource(new PostFetchResult([], []));

    DailyContentServer::tool(GetNewPosts::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('posts', 0)
                ->where('errors', [])
                ->where('classification', ['classified' => 0, 'failed' => 0, 'errors' => []]);
        });
});
