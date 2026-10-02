<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

function storageStateFixture(array $cookies): string
{
    $path = tempnam(sys_get_temp_dir(), 'scraper_state_').'.json';
    file_put_contents($path, json_encode(['cookies' => $cookies]));

    return $path;
}

function fakeBrowserExtraction(?array $cookies, ?string $error = null): void
{
    Process::fake(function (PendingProcess $process) use ($cookies, $error) {
        if (str_contains(implode(' ', (array) $process->command), 'read_browser_cookies.py')) {
            if ($error !== null) {
                return Process::result(output: json_encode(['error' => $error]), exitCode: 1);
            }

            return Process::result(output: json_encode(['cookies' => $cookies ?? []]));
        }

        return Process::result();
    });
}

function scraperConfig(array $overrides = []): void
{
    config(array_merge([
        'services.scraper.python' => '/usr/bin/python3',
        'services.scraper.accounts_db' => '/tmp/accounts.db',
        'services.scraper.account_label' => 'default',
        'services.scraper.browser' => 'brave',
        'services.scraper.storage_state' => '/nonexistent/state.json',
    ], $overrides));
}

test('scraper:import-cookies imports auth cookies from the storage state', function () {
    Process::fake();

    $path = storageStateFixture([
        ['name' => 'auth_token', 'value' => 'token-123'],
        ['name' => 'ct0', 'value' => 'csrf-456'],
        ['name' => 'guest_id', 'value' => 'ignored'],
    ]);

    scraperConfig(['services.scraper.storage_state' => $path]);

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

test('scraper:import-cookies imports cookies passed with the --cookies option', function () {
    Process::fake();
    scraperConfig();

    $this->artisan('scraper:import-cookies', ['--cookies' => 'auth_token=manual-token; ct0=manual-csrf'])
        ->expectsOutputToContain('Cookies imported')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => json_decode($process->input, true)['cookies'] === 'auth_token=manual-token; ct0=manual-csrf');
});

test('scraper:import-cookies rejects a --cookies value without auth_token and ct0', function () {
    Process::fake();
    scraperConfig();

    $this->artisan('scraper:import-cookies', ['--cookies' => 'auth_token=only'])
        ->expectsOutputToContain('ct0')
        ->assertFailed();

    Process::assertNothingRan();
});

test('scraper:import-cookies falls back to reading cookies from the browser', function () {
    fakeBrowserExtraction(['auth_token' => 'brave-token', 'ct0' => 'brave-csrf']);
    scraperConfig();

    $this->artisan('scraper:import-cookies')
        ->expectsOutputToContain('Cookies imported')
        ->assertSuccessful();

    Process::assertRan(function (PendingProcess $process) {
        $payload = json_decode($process->input, true);

        return $process->command === ['/usr/bin/python3', base_path('scraper/read_browser_cookies.py')]
            && $payload['browser'] === 'brave'
            && $payload['names'] === ['auth_token', 'ct0'];
    });

    Process::assertRan(fn (PendingProcess $process): bool => str_contains(implode(' ', (array) $process->command), 'import_cookies.py')
        && json_decode($process->input, true)['cookies'] === 'auth_token=brave-token; ct0=brave-csrf');
});

test('scraper:import-cookies uses the browser option over the configured browser', function () {
    fakeBrowserExtraction(['auth_token' => 'chrome-token', 'ct0' => 'chrome-csrf']);
    scraperConfig();

    $this->artisan('scraper:import-cookies', ['--browser' => 'chrome'])
        ->expectsOutputToContain('Cookies imported')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => str_contains(implode(' ', (array) $process->command), 'read_browser_cookies.py')
        && json_decode($process->input, true)['browser'] === 'chrome');
});

test('scraper:import-cookies fails with guidance when no source provides cookies', function () {
    fakeBrowserExtraction(null, 'secret-tool not found');
    scraperConfig();

    $this->artisan('scraper:import-cookies')
        ->expectsOutputToContain('secret-tool not found')
        ->expectsOutputToContain('--cookies')
        ->expectsOutputToContain('do_login')
        ->assertFailed();
});

test('scraper:import-cookies hints about the host PHP when the scraper python cannot run', function () {
    fakeBrowserExtraction(null, 'sh: exec: line 0: /path/python: not found');
    scraperConfig();

    $this->artisan('scraper:import-cookies')
        ->expectsOutputToContain('/usr/bin/php')
        ->assertFailed();
});

test('scraper:import-cookies reports a failed import process', function () {
    Process::fake(['*' => Process::result(output: '', errorOutput: 'ValueError: bad cookies', exitCode: 1)]);

    $path = storageStateFixture([
        ['name' => 'auth_token', 'value' => 'token-123'],
        ['name' => 'ct0', 'value' => 'csrf-456'],
    ]);

    scraperConfig(['services.scraper.storage_state' => $path]);

    $this->artisan('scraper:import-cookies')
        ->expectsOutputToContain('ValueError')
        ->assertFailed();

    unlink($path);
});

test('scraper:import-cookies expands ~ in the storage state path', function () {
    Process::fake();

    $home = sys_get_temp_dir().'/scraper-home-'.uniqid();
    mkdir($home.'/.twitter-mcp', 0777, true);
    file_put_contents($home.'/.twitter-mcp/state.json', json_encode(['cookies' => [
        ['name' => 'auth_token', 'value' => 'home-token'],
        ['name' => 'ct0', 'value' => 'home-csrf'],
    ]]));

    $previousHome = getenv('HOME');
    putenv("HOME={$home}");

    try {
        scraperConfig(['services.scraper.storage_state' => '~/.twitter-mcp/state.json']);

        $this->artisan('scraper:import-cookies')
            ->expectsOutputToContain('Cookies imported')
            ->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process): bool => str_contains(implode(' ', (array) $process->command), 'import_cookies.py')
            && json_decode($process->input, true)['cookies'] === 'auth_token=home-token; ct0=home-csrf');
    } finally {
        putenv('HOME='.($previousHome === false ? '' : $previousHome));
        unlink($home.'/.twitter-mcp/state.json');
        rmdir($home.'/.twitter-mcp');
        rmdir($home);
    }
});
