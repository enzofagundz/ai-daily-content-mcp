<?php

use App\Services\Twitter\FetchedPost;
use App\Services\Twitter\PostSource;
use App\Services\Twitter\PostSourceTarget;
use App\Services\Twitter\TwscrapePostSource;
use Carbon\CarbonImmutable;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;

beforeEach(function () {
    $this->source = new TwscrapePostSource(
        python: '/usr/bin/python3',
        script: base_path('scraper/x_posts.py'),
        accountsDb: '/tmp/accounts.db',
        timeout: 300,
    );
});

test('fetch sends targets with since, limit and accounts db to the scraper', function () {
    Process::fake();

    $this->source->fetch([
        new PostSourceTarget('theo', CarbonImmutable::parse('2026-10-01T00:00:00Z')),
        new PostSourceTarget('simonw'),
    ], 60);

    Process::assertRan(function (PendingProcess $process) {
        $payload = json_decode($process->input, true);

        return $process->command === ['/usr/bin/python3', base_path('scraper/x_posts.py')]
            && $process->timeout === 300
            && $payload['accounts_db'] === '/tmp/accounts.db'
            && $payload['limit'] === 60
            && $payload['targets'] === [
                ['username' => 'theo', 'since' => '2026-10-01T00:00:00+00:00'],
                ['username' => 'simonw', 'since' => null],
            ];
    });
});

test('fetch maps posts and drops replies and retweets', function () {
    Process::fake(['*' => Process::result(output: json_encode([
        'posts' => [
            ['external_id' => '111', 'url' => 'https://x.com/theo/status/111', 'text' => 'hello', 'published_at' => '2026-10-02T10:00:00+00:00', 'author' => 'Theo', 'username' => 'theo', 'is_reply' => false, 'is_retweet' => false],
            ['external_id' => '222', 'url' => 'https://x.com/theo/status/222', 'text' => 'a reply', 'published_at' => '2026-10-02T09:00:00+00:00', 'author' => 'Theo', 'username' => 'theo', 'is_reply' => true, 'is_retweet' => false],
            ['external_id' => '333', 'url' => 'https://x.com/theo/status/333', 'text' => 'a retweet', 'published_at' => '2026-10-02T08:00:00+00:00', 'author' => 'Someone', 'username' => 'someone', 'is_reply' => false, 'is_retweet' => true],
            ['external_id' => '444', 'url' => 'https://x.com/theo/status/444', 'text' => 'a quote', 'published_at' => '2026-10-02T07:00:00+00:00', 'author' => 'Theo', 'username' => 'theo', 'is_reply' => false, 'is_retweet' => false],
        ],
        'errors' => [],
    ]))]);

    $result = $this->source->fetch([new PostSourceTarget('theo')], 60);

    expect(array_map(fn (FetchedPost $post): string => $post->externalId, $result->posts))
        ->toBe(['111', '444']);

    $first = $result->posts[0];

    expect($first->author)->toBe('Theo')
        ->and($first->username)->toBe('theo')
        ->and($first->text)->toBe('hello')
        ->and($first->url)->toBe('https://x.com/theo/status/111')
        ->and($first->publishedAt->toIso8601String())->toBe('2026-10-02T10:00:00+00:00');
});

test('fetch reports per-profile errors from the scraper', function () {
    Process::fake(['*' => Process::result(output: json_encode([
        'posts' => [],
        'errors' => [['username' => 'ghost', 'message' => 'profile @ghost was not found on X']],
    ]))]);

    $result = $this->source->fetch([new PostSourceTarget('ghost')], 60);

    expect($result->posts)->toBe([])
        ->and($result->errors)->toBe(['ghost' => 'profile @ghost was not found on X']);
});

test('fetch maps global scraper errors to every target', function () {
    Process::fake(['*' => Process::result(output: json_encode([
        'posts' => [],
        'errors' => [['username' => null, 'message' => 'No twscrape account configured.']],
    ]))]);

    $result = $this->source->fetch([new PostSourceTarget('theo'), new PostSourceTarget('simonw')], 60);

    expect($result->errors)->toBe([
        'theo' => 'No twscrape account configured.',
        'simonw' => 'No twscrape account configured.',
    ]);
});

test('fetch reports a failed scraper process as errors for every target', function () {
    Process::fake(['*' => Process::result(
        output: '',
        errorOutput: "Traceback (most recent call last):\nImportError: boom",
        exitCode: 1,
    )]);

    $result = $this->source->fetch([new PostSourceTarget('theo')], 60);

    expect($result->posts)->toBe([])
        ->and($result->errors['theo'])->toContain('scraper failed')
        ->and($result->errors['theo'])->toContain('Traceback');
});

test('fetch reports an unexpected scraper failure as errors for every target', function () {
    Process::fake(function () {
        throw new RuntimeException('python executable not found');
    });

    $result = $this->source->fetch([new PostSourceTarget('theo')], 60);

    expect($result->errors['theo'])->toContain('scraper failed: python executable not found');
});

test('fetch reports a scraper timeout as errors for every target', function () {
    Process::fake(function () {
        $original = new SymfonyProcessTimedOutException(new SymfonyProcess(['sleep']), SymfonyProcessTimedOutException::TYPE_GENERAL);

        throw ProcessTimedOutException::make($original, Process::result());
    });

    $result = $this->source->fetch([new PostSourceTarget('theo')], 60);

    expect($result->errors['theo'])->toContain('timed out');
});

test('post source resolves to the twscrape adapter from the container', function () {
    expect(app(PostSource::class))->toBeInstanceOf(TwscrapePostSource::class);
});
