<?php

use App\Mcp\Servers\DailyContentServer;
use App\Mcp\Tools\FetchRecentPosts;
use App\Models\Profile;
use App\Services\Twitter\FetchedPost;
use App\Services\Twitter\PostFetchResult;
use App\Services\Twitter\PostSource;
use Carbon\CarbonImmutable;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\Support\FakePostSource;

function fakeSource(PostFetchResult $result): FakePostSource
{
    $fake = new FakePostSource($result);
    app()->instance(PostSource::class, $fake);

    return $fake;
}

test('fetch_recent_posts fetches every monitored profile by default', function () {
    Profile::factory()->create(['username' => 'theo']);
    Profile::factory()->create(['username' => 'simonw']);

    $fake = fakeSource(new PostFetchResult([
        new FetchedPost('1', 'theo', 'Theo', 'hello', 'https://x.com/theo/status/1', CarbonImmutable::parse('2026-10-02T10:00:00Z')),
    ], []));

    DailyContentServer::tool(FetchRecentPosts::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('posts', 1)
                ->where('posts.0.author', 'Theo')
                ->where('posts.0.username', 'theo')
                ->where('posts.0.text', 'hello')
                ->where('posts.0.url', 'https://x.com/theo/status/1')
                ->where('posts.0.published_at', '2026-10-02T10:00:00+00:00')
                ->where('errors', []);
        });

    expect(array_map(fn ($target): string => $target->username, $fake->calls[0]['targets']))->toBe(['simonw', 'theo'])
        ->and($fake->calls[0]['limit'])->toBe(20);
});

test('fetch_recent_posts fetches only the requested profile and passes the limit', function () {
    Profile::factory()->create(['username' => 'theo']);
    Profile::factory()->create(['username' => 'simonw']);

    $fake = fakeSource(new PostFetchResult([], []));

    DailyContentServer::tool(FetchRecentPosts::class, ['username' => '@theo', 'limit' => 5])
        ->assertOk();

    expect($fake->calls)->toHaveCount(1)
        ->and($fake->calls[0]['targets'])->toHaveCount(1)
        ->and($fake->calls[0]['targets'][0]->username)->toBe('theo')
        ->and($fake->calls[0]['limit'])->toBe(5);
});

test('fetch_recent_posts returns posts sorted by published date descending', function () {
    Profile::factory()->create(['username' => 'theo']);

    fakeSource(new PostFetchResult([
        new FetchedPost('old', 'theo', 'Theo', 'older', 'https://x.com/theo/status/old', CarbonImmutable::parse('2026-10-01T08:00:00Z')),
        new FetchedPost('new', 'theo', 'Theo', 'newer', 'https://x.com/theo/status/new', CarbonImmutable::parse('2026-10-02T08:00:00Z')),
    ], []));

    DailyContentServer::tool(FetchRecentPosts::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('posts', 2)
                ->where('posts.0.text', 'newer')
                ->where('posts.1.text', 'older')
                ->where('errors', []);
        });
});

test('fetch_recent_posts fails when the username is not monitored', function () {
    $fake = fakeSource(new PostFetchResult([], []));

    DailyContentServer::tool(FetchRecentPosts::class, ['username' => 'ghost'])
        ->assertHasErrors(['not monitored']);

    expect($fake->calls)->toBe([]);
});

test('fetch_recent_posts rejects an invalid limit', function (int $limit) {
    Profile::factory()->create(['username' => 'theo']);
    fakeSource(new PostFetchResult([], []));

    DailyContentServer::tool(FetchRecentPosts::class, ['limit' => $limit])
        ->assertHasErrors();
})->with([0, 101]);

test('fetch_recent_posts rejects an invalid username', function (string $username) {
    $fake = fakeSource(new PostFetchResult([], []));

    DailyContentServer::tool(FetchRecentPosts::class, ['username' => $username])
        ->assertHasErrors();

    expect($fake->calls)->toBe([]);
})->with(['', 'not a handle!']);

test('fetch_recent_posts does not write to the database', function () {
    Profile::factory()->create(['username' => 'theo']);

    fakeSource(new PostFetchResult([
        new FetchedPost('1', 'theo', 'Theo', 'hello', 'https://x.com/theo/status/1', CarbonImmutable::parse('2026-10-02T10:00:00Z')),
    ], []));

    DailyContentServer::tool(FetchRecentPosts::class, [])->assertOk();

    $this->assertDatabaseCount('profiles', 1);
});

test('fetch_recent_posts reports partial profile failures without failing the call', function () {
    Profile::factory()->create(['username' => 'theo']);
    Profile::factory()->create(['username' => 'simonw']);

    fakeSource(new PostFetchResult([], ['simonw' => 'profile @simonw was not found on X']));

    DailyContentServer::tool(FetchRecentPosts::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->where('posts', [])
                ->has('errors', 1)
                ->where('errors.0.username', 'simonw')
                ->where('errors.0.message', 'profile @simonw was not found on X');
        });
});

test('fetch_recent_posts fails when every profile fails', function () {
    Profile::factory()->create(['username' => 'theo']);
    Profile::factory()->create(['username' => 'simonw']);

    fakeSource(new PostFetchResult([], [
        'theo' => 'boom',
        'simonw' => 'boom',
    ]));

    DailyContentServer::tool(FetchRecentPosts::class, [])
        ->assertHasErrors(['boom']);
});

test('fetch_recent_posts explains when nothing is monitored', function () {
    fakeSource(new PostFetchResult([], []));

    DailyContentServer::tool(FetchRecentPosts::class, [])
        ->assertOk()
        ->assertSee('No profiles are being monitored')
        ->assertStructuredContent(['posts' => [], 'errors' => []]);
});
