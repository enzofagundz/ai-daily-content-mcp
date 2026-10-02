<?php

namespace App\Mcp\Tools;

use App\Models\Profile;
use App\Rules\XProfileHandle;
use App\Services\Twitter\FetchedPost;
use App\Services\Twitter\PostSource;
use App\Services\Twitter\PostSourceTarget;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsOpenWorld]
#[Description('Fetches recent posts from monitored X profiles without marking anything as seen. Already fetched posts are included.')]
class FetchRecentPosts extends Tool
{
    public function __construct(
        private PostSource $source,
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'username' => ['nullable', 'string', new XProfileHandle],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [
            'limit.min' => 'The limit must be at least 1.',
            'limit.max' => 'The limit must be at most 100.',
        ]);

        $profiles = $this->resolveProfiles($validated['username'] ?? null);

        if ($profiles instanceof Response) {
            return $profiles;
        }

        if ($profiles === []) {
            return Response::make(Response::text('No profiles are being monitored. Add one with add_profile.'))
                ->withStructuredContent(['posts' => [], 'errors' => []]);
        }

        $targets = array_map(
            fn (Profile $profile): PostSourceTarget => new PostSourceTarget($profile->username),
            $profiles,
        );

        $result = $this->source->fetch($targets, $validated['limit'] ?? 20);

        if ($result->posts === [] && count($result->errors) >= count($targets)) {
            $messages = collect($result->errors)
                ->map(fn (string $message, string $username): string => "@{$username}: {$message}")
                ->implode('; ');

            return Response::error("Fetch failed for every monitored profile. {$messages}");
        }

        $posts = collect($result->posts)
            ->sortByDesc(fn (FetchedPost $post): int => $post->publishedAt->getTimestamp())
            ->map(fn (FetchedPost $post): array => [
                'author' => $post->author,
                'username' => $post->username,
                'text' => $post->text,
                'url' => $post->url,
                'published_at' => $post->publishedAt->toIso8601String(),
            ])
            ->values()
            ->all();

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
            'username' => $schema->string()
                ->description('Only fetch this profile (username, @handle or profile URL). Omit to fetch every monitored profile.'),
            'limit' => $schema->integer()
                ->description('Maximum posts fetched per profile (default 20, max 100).')
                ->min(1)
                ->max(100),
        ];
    }

    /**
     * @return list<Profile>|Response
     */
    private function resolveProfiles(?string $username): array|Response
    {
        if ($username === null) {
            $profiles = [];

            foreach (Profile::query()->orderBy('username')->get() as $profile) {
                $profiles[] = $profile;
            }

            return $profiles;
        }

        $normalized = Profile::usernameFromInput($username);
        $profile = Profile::query()->where('username', $normalized)->first();

        if ($profile === null) {
            return Response::error("@{$normalized} is not monitored. Add it with add_profile first.");
        }

        return [$profile];
    }
}
