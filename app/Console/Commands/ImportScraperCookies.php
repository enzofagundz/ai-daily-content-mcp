<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class ImportScraperCookies extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scraper:import-cookies
        {--cookies= : Cookie string containing auth_token and ct0, copied from your browser}
        {--browser= : Browser profile to read cookies from (brave, chrome, chromium)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import X auth cookies into the twscrape accounts pool, from the --cookies option, the twitter-mcp storage state, or a local browser profile';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $manual = trim((string) $this->option('cookies'));

        if ($manual !== '') {
            if (! $this->hasAuthCookies($manual)) {
                $this->error('The --cookies value must contain both auth_token and ct0.');

                return self::FAILURE;
            }

            return $this->import($manual);
        }

        $cookies = $this->cookiesFromStorageState() ?? $this->cookiesFromBrowser();

        if ($cookies === null) {
            return $this->guideToSources();
        }

        return $this->import($cookies);
    }

    private function cookiesFromStorageState(): ?string
    {
        $storageState = $this->expandHome((string) config('services.scraper.storage_state'));

        if (! is_file($storageState)) {
            return null;
        }

        $state = json_decode((string) file_get_contents($storageState), true);

        if (! is_array($state) || ! is_array($state['cookies'] ?? null)) {
            return null;
        }

        $cookies = [];

        foreach ($state['cookies'] as $cookie) {
            $name = is_array($cookie) ? ($cookie['name'] ?? null) : null;
            $value = is_array($cookie) ? ($cookie['value'] ?? null) : null;

            if (in_array($name, ['auth_token', 'ct0'], true) && is_string($value)) {
                $cookies[$name] = $value;
            }
        }

        return $this->cookieString($cookies);
    }

    private function cookiesFromBrowser(): ?string
    {
        $browser = trim((string) ($this->option('browser') ?: config('services.scraper.browser')));

        $payload = json_encode([
            'browser' => $browser,
            'names' => ['auth_token', 'ct0'],
        ], JSON_THROW_ON_ERROR);

        $result = Process::timeout(60)->input($payload)->run([
            (string) config('services.scraper.python'),
            (string) config('services.scraper.read_browser_script'),
        ]);

        $decoded = json_decode($result->output(), true);

        if (is_array($decoded) && is_array($decoded['cookies'] ?? null)) {
            $cookies = array_filter($decoded['cookies'], is_string(...));

            $string = $this->cookieString($cookies);

            if ($string !== null) {
                return $string;
            }
        }

        $detail = is_array($decoded) ? ($decoded['error'] ?? null) : null;
        $detail ??= trim($result->errorOutput() ?: $result->output());

        if (is_string($detail) && $detail !== '') {
            $this->line('Browser cookie extraction failed: '.Str::limit(str_replace("\n", ' ', $detail), 300, ''));

            if (str_contains($detail, 'not found')) {
                $this->line('Hint: if this runs inside the lerd container, use the host PHP: /usr/bin/php artisan scraper:import-cookies');
            }
        }

        return null;
    }

    private function import(string $cookies): int
    {
        $payload = json_encode([
            'accounts_db' => (string) config('services.scraper.accounts_db'),
            'username' => (string) config('services.scraper.account_label'),
            'cookies' => $cookies,
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

    private function guideToSources(): int
    {
        $this->error('No auth cookies found.');
        $this->line('Option 1 — copy auth_token and ct0 from your browser (DevTools > Application > Cookies > x.com) and run:');
        $this->line('  php artisan scraper:import-cookies --cookies="auth_token=...; ct0=..."');
        $this->line('Option 2 — log in with the twitter-mcp do_login script and re-run this command.');

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $cookies
     */
    private function cookieString(array $cookies): ?string
    {
        if (! isset($cookies['auth_token'], $cookies['ct0'])) {
            return null;
        }

        return "auth_token={$cookies['auth_token']}; ct0={$cookies['ct0']}";
    }

    private function hasAuthCookies(string $cookies): bool
    {
        return str_contains($cookies, 'auth_token=') && str_contains($cookies, 'ct0=');
    }

    private function expandHome(string $path): string
    {
        if (! str_starts_with($path, '~/')) {
            return $path;
        }

        return (getenv('HOME') ?: sys_get_temp_dir()).substr($path, 1);
    }
}
