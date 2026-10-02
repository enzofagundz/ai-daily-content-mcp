<?php

namespace App\Mcp\Tools;

use App\Models\Post;
use App\Services\Classification\ClassificationException;
use App\Services\Classification\PostClassifier;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsOpenWorld]
#[Description('Classifies the posts still pending classification, up to a limit, and reports how many were classified, how many failed and how many remain. Pass retry_failed to also retry posts whose previous attempt failed.')]
class ClassifyPendingPosts extends Tool
{
    /**
     * Default posts classified per call.
     */
    private const DEFAULT_LIMIT = 20;

    public function __construct(
        private PostClassifier $classifier,
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'retry_failed' => ['nullable', 'boolean'],
        ], [
            'limit.min' => 'The limit must be at least 1.',
            'limit.max' => 'The limit must be at most 100.',
        ]);

        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);
        $retryFailed = (bool) ($validated['retry_failed'] ?? false);

        $statuses = $retryFailed
            ? [Post::CLASSIFICATION_PENDING, Post::CLASSIFICATION_FAILED]
            : [Post::CLASSIFICATION_PENDING];

        $posts = Post::query()
            ->with('profile')
            ->whereIn('classification_status', $statuses)
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();

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

        return Response::structured([
            'classified' => $classified,
            'failed' => $failed,
            'remaining' => Post::query()->whereIn('classification_status', $statuses)->count(),
            'errors' => $errors,
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
                ->description('Maximum posts classified in this call (default 20, max 100).')
                ->min(1)
                ->max(100),
            'retry_failed' => $schema->boolean()
                ->description('Also retry posts whose previous classification failed (default false).'),
        ];
    }
}
