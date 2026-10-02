<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class ImportScraperCookies extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scraper:import-cookies';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import auth_token/ct0 cookies from the twitter-mcp browser profile into the twscrape accounts pool';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $storageState = $this->expandHome((string) config('services.scraper.storage_state'));

        if (! is_file($storageState)) {
            $this->error("Storage state not found at {$storageState}.");

            return $this->guideToLogin();
        }

        $state = json_decode((string) file_get_contents($storageState), true);

        if (! is_array($state) || ! is_array($state['cookies'] ?? null)) {
            $this->error('The storage state is not a valid Playwright storage state file.');

            return self::FAILURE;
        }

        $cookies = [];

        foreach ($state['cookies'] as $cookie) {
            $name = is_array($cookie) ? ($cookie['name'] ?? null) : null;
            $value = is_array($cookie) ? ($cookie['value'] ?? null) : null;

            if (in_array($name, ['auth_token', 'ct0'], true) && is_string($value)) {
                $cookies[$name] = $value;
            }
        }

        if (! isset($cookies['auth_token'], $cookies['ct0'])) {
            $this->error('The storage state has no auth_token/ct0 cookies; the twitter-mcp profile is not logged in.');

            return $this->guideToLogin();
        }

        $payload = json_encode([
            'accounts_db' => (string) config('services.scraper.accounts_db'),
            'username' => (string) config('services.scraper.account_label'),
            'cookies' => "auth_token={$cookies['auth_token']}; ct0={$cookies['ct0']}",
        ], JSON_THROW_ON_ERROR);

        $result = Process::timeout(60)->input($payload)->run([
            (string) config('services.scraper.python'),
            base_path('scraper/import_cookies.py'),
        ]);

        if (! $result->successful()) {
            $this->error('Import failed: '.trim($result->errorOutput() ?: $result->output()));

            return self::FAILURE;
        }

        $this->info('Cookies imported into the twscrape accounts pool.');

        return self::SUCCESS;
    }

    private function guideToLogin(): int
    {
        $this->line('Log in first: cd ~/Projects/twitter-mcp && .venv/bin/python do_login.py');

        return self::FAILURE;
    }

    private function expandHome(string $path): string
    {
        if (! str_starts_with($path, '~/')) {
            return $path;
        }

        return (getenv('HOME') ?: sys_get_temp_dir()).substr($path, 1);
    }
}
