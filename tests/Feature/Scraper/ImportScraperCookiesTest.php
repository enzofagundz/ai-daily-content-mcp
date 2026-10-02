<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

function storageStateFixture(array $cookies): string
{
    $path = tempnam(sys_get_temp_dir(), 'scraper_state_').'.json';
    file_put_contents($path, json_encode(['cookies' => $cookies]));

    return $path;
}

test('scraper:import-cookies imports auth cookies into the twscrape pool', function () {
    Process::fake();

    $path = storageStateFixture([
        ['name' => 'auth_token', 'value' => 'token-123'],
        ['name' => 'ct0', 'value' => 'csrf-456'],
        ['name' => 'guest_id', 'value' => 'ignored'],
    ]);

    config([
        'services.scraper.python' => '/usr/bin/python3',
        'services.scraper.storage_state' => $path,
        'services.scraper.accounts_db' => '/tmp/accounts.db',
        'services.scraper.account_label' => 'default',
    ]);

    $this->artisan('scraper:import-cookies')
        ->expectsOutputToContain('Cookies imported')
        ->assertSuccessful();

    Process::assertRan(function (PendingProcess $process) {
        $payload = json_decode($process->input, true);

        return $process->command === ['/usr/bin/python3', base_path('scraper/import_cookies.py')]
            && $payload['accounts_db'] === '/tmp/accounts.db'
            && $payload['username'] === 'default'
            && $payload['cookies'] === 'auth_token=token-123; ct0=csrf-456';
    });

    unlink($path);
});

test('scraper:import-cookies fails with guidance when the storage state is missing', function () {
    Process::fake();

    config(['services.scraper.storage_state' => '/nonexistent/state.json']);

    $this->artisan('scraper:import-cookies')
        ->expectsOutputToContain('do_login.py')
        ->assertFailed();
});

test('scraper:import-cookies expands ~ in the storage state path', function () {
    Process::fake();

    config(['services.scraper.storage_state' => '~/definitely-missing-state.json']);

    $this->artisan('scraper:import-cookies')
        ->expectsOutputToContain('Storage state not found at '.getenv('HOME').'/definitely-missing-state.json')
        ->assertFailed();
});

test('scraper:import-cookies fails when auth cookies are absent', function () {
    Process::fake();

    $path = storageStateFixture([
        ['name' => 'guest_id', 'value' => 'only-guest'],
    ]);

    config(['services.scraper.storage_state' => $path]);

    $this->artisan('scraper:import-cookies')
        ->expectsOutputToContain('auth_token')
        ->assertFailed();

    unlink($path);
});

test('scraper:import-cookies reports a failed import process', function () {
    Process::fake(['*' => Process::result(output: '', errorOutput: 'ValueError: bad cookies', exitCode: 1)]);

    $path = storageStateFixture([
        ['name' => 'auth_token', 'value' => 'token-123'],
        ['name' => 'ct0', 'value' => 'csrf-456'],
    ]);

    config(['services.scraper.storage_state' => $path]);

    $this->artisan('scraper:import-cookies')
        ->expectsOutputToContain('ValueError')
        ->assertFailed();

    unlink($path);
});
