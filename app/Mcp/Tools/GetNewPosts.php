<?php

namespace App\Mcp\Tools;

use App\Models\Post;
use App\Models\Profile;
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
#[Description('Returns monitored posts that have never been presented before, newest first, and marks them as presented. Posts beyond the limit stay pending for later calls.')]
class GetNewPosts extends Tool
{
    /**
     * Maximum posts fetched per profile when looking for new posts.
     */
    private const SCRAPE_CAP = 60;

    public function __construct(
        private PostSource $source,
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
                ->withStructuredContent(['posts' => [], 'errors' => []]);
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

        $posts = DB::transaction(function () use ($profiles, $result, $validated): array {
            $this->persist($profiles, $result->posts);

            return $this->present((int) ($validated['limit'] ?? 20));
        });

        $errors = collect($result->errors)
            ->map(fn (string $message, string $username): array => [
                'username' => $username,
                'message' => $message,
            ])
            ->values()
            ->all();

        return Response::structured(['posts' => $posts, 'errors' => $errors]);
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
     */
    private function persist(Collection $profiles, array $fetchedPosts): void
    {
        $byUsername = $profiles->keyBy('username');

        foreach ($fetchedPosts as $post) {
            $profile = $byUsername->get($post->username);

            if ($profile === null) {
                continue;
            }

            Post::query()->firstOrCreate(['external_id' => $post->externalId], [
                'profile_id' => $profile->id,
                'url' => $post->url,
                'text' => $post->text,
                'author' => $post->author,
                'published_at' => $post->publishedAt,
            ]);
        }
    }

    /**
     * @return list<array{author: string, username: string, text: string, url: string, published_at: string}>
     */
    private function present(int $limit): array
    {
        $posts = Post::query()
            ->with('profile')
            ->whereNull('presented_at')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();

        if ($posts->isEmpty()) {
            return [];
        }

        Post::query()->whereIn('id', $posts->pluck('id'))->update(['presented_at' => now()]);

        $presented = [];

        foreach ($posts as $post) {
            $presented[] = [
                'author' => $post->author,
                'username' => $post->profile->username,
                'text' => $post->text,
                'url' => $post->url,
                'published_at' => $post->published_at->toIso8601String(),
            ];
        }

        return $presented;
    }
}
