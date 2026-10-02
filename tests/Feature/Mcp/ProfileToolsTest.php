<?php

use App\Mcp\Servers\DailyContentServer;
use App\Mcp\Tools\AddProfile;
use App\Mcp\Tools\ListProfiles;
use App\Mcp\Tools\RemoveProfile;

test('add_profile registers a profile and list_profiles returns it', function () {
    DailyContentServer::tool(AddProfile::class, ['url' => 'https://x.com/theo'])
        ->assertOk();

    DailyContentServer::tool(ListProfiles::class, [])
        ->assertOk()
        ->assertSee('theo');
});

test('add_profile normalizes URLs, handles and usernames', function (string $input) {
    DailyContentServer::tool(AddProfile::class, ['url' => $input])
        ->assertOk();

    DailyContentServer::tool(ListProfiles::class, [])
        ->assertOk()
        ->assertSee('@theo — https://x.com/theo');
})->with([
    'https://x.com/theo',
    'https://twitter.com/Theo',
    'https://www.x.com/theo',
    'https://x.com/theo/',
    'https://x.com/theo/status/1234567890123456789',
    '  @theo  ',
    '@Theo',
    'Theo',
]);

test('add_profile rejects invalid input', function (string $input) {
    DailyContentServer::tool(AddProfile::class, ['url' => $input])
        ->assertHasErrors();
})->with([
    '',
    'https://youtube.com/@theo',
    'theo!',
    'this username is way too long to be valid',
    'https://x.com/',
]);

test('add_profile is idempotent for the same profile', function () {
    DailyContentServer::tool(AddProfile::class, ['url' => 'https://x.com/theo'])->assertOk();
    DailyContentServer::tool(AddProfile::class, ['url' => '@theo'])->assertOk();

    $this->assertDatabaseCount('profiles', 1);
});

test('remove_profile soft deletes the profile and it disappears from the list', function () {
    DailyContentServer::tool(AddProfile::class, ['url' => 'theo'])->assertOk();

    DailyContentServer::tool(RemoveProfile::class, ['username' => '@theo'])
        ->assertOk()
        ->assertSee('no longer monitored');

    DailyContentServer::tool(ListProfiles::class, [])
        ->assertOk()
        ->assertDontSee('@theo');

    $this->assertSoftDeleted('profiles', ['username' => 'theo']);
});

test('remove_profile rejects invalid input', function () {
    DailyContentServer::tool(RemoveProfile::class, ['username' => 'not a handle!'])
        ->assertHasErrors();
});

test('remove_profile fails when the profile is not monitored', function () {
    DailyContentServer::tool(RemoveProfile::class, ['username' => 'ghost'])
        ->assertHasErrors(['not being monitored']);
});

test('re-adding a removed profile restores it without duplicating', function () {
    DailyContentServer::tool(AddProfile::class, ['url' => 'theo'])->assertOk();
    DailyContentServer::tool(RemoveProfile::class, ['username' => 'theo'])->assertOk();

    DailyContentServer::tool(AddProfile::class, ['url' => '@theo'])->assertOk();

    $this->assertDatabaseCount('profiles', 1);
    $this->assertNotSoftDeleted('profiles', ['username' => 'theo']);

    DailyContentServer::tool(ListProfiles::class, [])
        ->assertOk()
        ->assertSee('@theo — https://x.com/theo');
});
