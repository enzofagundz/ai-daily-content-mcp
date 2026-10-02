<?php

use App\Mcp\Servers\DailyContentServer;
use App\Mcp\Tools\GetNewPosts;
use App\Mcp\Tools\RemoveProfile;
use App\Models\Post;
use App\Models\Profile;
use App\Services\Twitter\PostFetchResult;
use Carbon\CarbonImmutable;
use Illuminate\Testing\Fluent\AssertableJson;

test('get_new_posts persists fetched posts and returns them newest first, marked as presented', function () {
    Profile::factory()->create(['username' => 'theo']);

    fakeSource(new PostFetchResult([
        fetchedPost('1', 'theo', '2026-10-01T08:00:00Z'),
        fetchedPost('2', 'theo', '2026-10-02T08:00:00Z'),
    ], []));

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
                ->where('errors', []);
        });

    $this->assertDatabaseCount('posts', 2);
    $this->assertDatabaseHas('posts', ['external_id' => '1', 'author' => 'Theo']);
    $this->assertDatabaseMissing('posts', ['presented_at' => null]);
});

test('get_new_posts does not return a post twice', function () {
    Profile::factory()->create(['username' => 'theo']);

    fakeSource(new PostFetchResult([
        fetchedPost('1', 'theo', '2026-10-01T08:00:00Z'),
    ], []));

    DailyContentServer::tool(GetNewPosts::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('posts', 1)->where('errors', []);
        });

    DailyContentServer::tool(GetNewPosts::class, [])
        ->assertOk()
        ->assertStructuredContent(['posts' => [], 'errors' => []]);

    $this->assertDatabaseCount('posts', 1);
});

test('get_new_posts keeps the excess pending and returns it on later calls', function () {
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

    DailyContentServer::tool(GetNewPosts::class, ['limit' => 20])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('posts', 20)
                ->where('posts.0.url', 'https://x.com/theo/status/30')
                ->where('posts.19.url', 'https://x.com/theo/status/11')
                ->where('errors', []);
        });

    $this->assertDatabaseCount('posts', 30);

    DailyContentServer::tool(GetNewPosts::class, ['limit' => 20])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('posts', 10)
                ->where('posts.0.url', 'https://x.com/theo/status/10')
                ->where('posts.9.url', 'https://x.com/theo/status/1')
                ->where('errors', []);
        });

    DailyContentServer::tool(GetNewPosts::class, ['limit' => 20])
        ->assertOk()
        ->assertStructuredContent(['posts' => [], 'errors' => []]);
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
    Profile::factory()->create(['username' => 'theo']);
    Profile::factory()->create(['username' => 'simonw']);

    fakeSource(new PostFetchResult([
        fetchedPost('1', 'theo', '2026-10-01T08:00:00Z'),
    ], ['simonw' => 'profile @simonw was not found on X']));

    DailyContentServer::tool(GetNewPosts::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('posts', 1)
                ->has('errors', 1)
                ->where('errors.0.username', 'simonw')
                ->where('errors.0.message', 'profile @simonw was not found on X');
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
