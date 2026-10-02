<?php

namespace App\Services\Twitter;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

final class TwscrapePostSource implements PostSource
{
    public function __construct(
        private string $python,
        private string $script,
        private string $accountsDb,
        private int $timeout,
    ) {}

    public function fetch(array $targets, int $limit): PostFetchResult
    {
        if ($targets === []) {
            return new PostFetchResult([], []);
        }

        $payload = json_encode([
            'accounts_db' => $this->accountsDb,
            'limit' => $limit,
            'targets' => array_map(fn (PostSourceTarget $target): array => [
                'username' => $target->username,
                'since' => $target->since?->toIso8601String(),
            ], $targets),
        ], JSON_THROW_ON_ERROR);

        try {
            $process = Process::timeout($this->timeout)
                ->input($payload)
                ->run([$this->python, $this->script]);
        } catch (ProcessTimedOutException) {
            return PostFetchResult::failed($targets, "scraper timed out after {$this->timeout}s");
        } catch (Throwable $exception) {
            return PostFetchResult::failed($targets, 'scraper failed: '.$exception->getMessage());
        }

        $decoded = json_decode($process->output(), true);

        if (! is_array($decoded)) {
            return PostFetchResult::failed($targets, $this->failureMessage($process));
        }

        $posts = $this->mapPosts($decoded['posts'] ?? []);
        $errors = $this->mapErrors($decoded['errors'] ?? [], $targets);

        if (! $process->successful() && $posts === [] && $errors === []) {
            return PostFetchResult::failed($targets, $this->failureMessage($process));
        }

        return new PostFetchResult($posts, $errors);
    }

    /**
     * @param  array<int, mixed>  $rawPosts
     * @return list<FetchedPost>
     */
    private function mapPosts(array $rawPosts): array
    {
        $posts = [];

        foreach ($rawPosts as $rawPost) {
            if (! is_array($rawPost)) {
                continue;
            }

            if (($rawPost['is_reply'] ?? false) || ($rawPost['is_retweet'] ?? false)) {
                continue;
            }

            $posts[] = new FetchedPost(
                externalId: (string) $rawPost['external_id'],
                username: (string) $rawPost['username'],
                author: (string) $rawPost['author'],
                text: (string) $rawPost['text'],
                url: (string) $rawPost['url'],
                publishedAt: CarbonImmutable::parse($rawPost['published_at']),
            );
        }

        return $posts;
    }

    /**
     * @param  array<int, mixed>  $rawErrors
     * @param  list<PostSourceTarget>  $targets
     * @return array<string, string>
     */
    private function mapErrors(array $rawErrors, array $targets): array
    {
        $errors = [];

        foreach ($rawErrors as $rawError) {
            if (! is_array($rawError) || ($rawError['message'] ?? '') === '') {
                continue;
            }

            $message = (string) $rawError['message'];
            $username = $rawError['username'] ?? null;

            if ($username === null || $username === '') {
                foreach ($targets as $target) {
                    $errors[$target->username] = $message;
                }

                continue;
            }

            $errors[(string) $username] = $message;
        }

        return $errors;
    }

    private function failureMessage(ProcessResult $process): string
    {
        $detail = trim($process->errorOutput()) ?: trim($process->output());

        if ($detail === '') {
            return 'scraper failed';
        }

        return 'scraper failed: '.Str::limit(str_replace("\n", ' ', $detail), 300, '');
    }
}
