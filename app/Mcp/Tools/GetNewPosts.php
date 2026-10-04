<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\ClassifiedPostPayload;
use App\Models\Post;
use App\Models\Profile;
use App\Services\Classification\ClassificationException;
use App\Services\Classification\PostClassifier;
use App\Services\Twitter\FetchedPost;
use App\Services\Twitter\PostSource;
use App\Services\Twitter\PostSourceTarget;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsOpenWorld]
#[Description('Returns monitored posts published within the recency window that have never been presented before, newest first, with their classification, and marks them as presented. Posts beyond the limit stay pending for later calls.')]
class GetNewPosts extends Tool
{
    /**
     * Maximum posts fetched per profile when looking for new posts.
     */
    private const SCRAPE_CAP = 60;

    public function __construct(
        private PostSource $source,
        private PostClassifier $classifier,
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [
            'limit.min' => 'The limit must be at least 1.',
            'limit.max' => 'The limit must be at most 100.',
        ]);

        $profiles = Profile::query()->orderBy('username')->get();

        if ($profiles->isEmpty()) {
            return Response::make(Response::text('No profiles are being monitored. Add one with add_profile.'))
                ->withStructuredContent([
                    'posts' => [],
                    'errors' => [],
                    'classification' => ['classified' => 0, 'failed' => 0, 'errors' => []],
                ]);
        }

        $targets = [];

        foreach ($profiles as $profile) {
            $targets[] = new PostSourceTarget($profile->username, $this->lastSeenAt($profile));
        }

        $result = $this->source->fetch($targets, self::SCRAPE_CAP);

        if ($result->posts === [] && count($result->errors) >= count($targets)) {
            $messages = collect($result->errors)
                ->map(fn (string $message, string $username): string => "@{$username}: {$message}")
                ->implode('; ');

            return Response::error("Fetch failed for every monitored profile. {$messages}");
        }

        $created = DB::transaction(fn (): array => $this->persist($profiles, $result->posts));

        $posts = $this->pendingPosts((int) ($validated['limit'] ?? 20));

        $classification = $this->classify($created);

        $errors = collect($result->errors)
            ->map(fn (string $message, string $username): array => [
                'username' => $username,
                'message' => $message,
            ])
            ->values()
            ->all();

        $payload = $posts
            ->map(fn (Post $post): array => ClassifiedPostPayload::for($post->refresh()))
            ->all();

        $this->markPresented($posts);

        return Response::structured([
            'posts' => $payload,
            'errors' => $errors,
            'classification' => $classification,
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Maximum posts returned in this call (default 20, max 100). The rest stays pending for later calls.')
                ->min(1)
                ->max(100),
        ];
    }

    private function lastSeenAt(Profile $profile): ?CarbonImmutable
    {
        $newest = $profile->posts()->max('published_at');

        return $newest === null ? null : CarbonImmutable::parse($newest);
    }

    /**
     * @param  Collection<int, Profile>  $profiles
     * @param  list<FetchedPost>  $fetchedPosts
     * @return list<Post>
     */
    private function persist(Collection $profiles, array $fetchedPosts): array
    {
        $byUsername = $profiles->keyBy('username');
        $created = [];

        foreach ($fetchedPosts as $post) {
            $profile = $byUsername->get($post->username);

            if ($profile === null) {
                continue;
            }

            $stored = Post::query()->firstOrCreate(['external_id' => $post->externalId], [
                'profile_id' => $profile->id,
                'url' => $post->url,
                'text' => $post->text,
                'author' => $post->author,
                'published_at' => $post->publishedAt,
            ]);

            if ($stored->wasRecentlyCreated) {
                $created[] = $stored;
            }
        }

        return $created;
    }

    /**
     * Classify every newly collected post, isolating each failure.
     *
     * @param  list<Post>  $posts
     * @return array{classified: int, failed: int, errors: list<array{post_id: int, message: string}>}
     */
    private function classify(array $posts): array
    {
        $classified = 0;
        $failed = 0;
        $errors = [];

        foreach ($posts as $post) {
            try {
                $this->classifier->classify($post);
                $classified++;
            } catch (ClassificationException $exception) {
                $failed++;
                $errors[] = [
                    'post_id' => $post->id,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return [
            'classified' => $classified,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * Select the newest pending posts published within the recency floor.
     *
     * The floor is the recency window, extended back to the last successful
     * presentation (capped by the max lookback) so a missed run does not drop
     * posts. Nothing is marked here: posts are only marked as presented once
     * the response payload has been built, so a failed call never consumes them.
     *
     * @return Collection<int, Post>
     */
    private function pendingPosts(int $limit): Collection
    {
        $floor = now()->subHours((int) config('content.window_hours'));

        $lastPresentation = Post::query()->max('presented_at');

        if ($lastPresentation !== null) {
            $floor = $floor->min(CarbonImmutable::parse($lastPresentation));
        }

        $floor = $floor->max(now()->subHours((int) config('content.max_lookback_hours')));

        return Post::query()
            ->fromMonitoredProfile()
            ->with('profile')
            ->whereNull('presented_at')
            ->where('published_at', '>=', $floor)
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  Collection<int, Post>  $posts
     */
    private function markPresented(Collection $posts): void
    {
        if ($posts->isEmpty()) {
            return;
        }

        Post::query()->whereIn('id', $posts->pluck('id'))->update(['presented_at' => now()]);
    }
}
