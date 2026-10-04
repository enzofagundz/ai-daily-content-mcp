<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\ClassifiedPostPayload;
use App\Models\Post;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Returns the standing list of posts classified as relevant candidates for LinkedIn content, newest first, with their classification signals. Manual lookup only: the daily digest is built from get_new_posts, which returns candidates already classified. Read-only: never classifies or reclassifies a post.')]
class GetRelevantPosts extends Tool
{
    /**
     * Default posts returned per call.
     */
    private const DEFAULT_LIMIT = 10;

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

        $posts = Post::query()
            ->fromMonitoredProfile()
            ->with('profile')
            ->where('classification_status', Post::CLASSIFICATION_CLASSIFIED)
            ->where('classification_relevant', true)
            ->orderByDesc('published_at')
            ->limit((int) ($validated['limit'] ?? self::DEFAULT_LIMIT))
            ->get();

        return Response::structured([
            'posts' => $posts->map(fn (Post $post): array => ClassifiedPostPayload::for($post))->all(),
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
                ->description('Maximum relevant posts returned (default 10, max 100).')
                ->min(1)
                ->max(100),
        ];
    }
}
