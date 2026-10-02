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
#[Description('Classifies a stored post with Cloudflare Clef, persisting the structured result. Use it to classify or explicitly reclassify a single post; get_new_posts classifies new posts automatically.')]
class ClassifyPost extends Tool
{
    public function __construct(
        private PostClassifier $classifier,
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'post_id' => ['required', 'integer', 'min:1'],
        ], [
            'post_id.required' => 'The post id is required.',
            'post_id.min' => 'The post id must be a positive integer.',
        ]);

        $postId = (int) $validated['post_id'];

        $post = Post::query()->with('profile')->find($postId);

        if ($post === null) {
            return Response::error("Post {$postId} was not found.");
        }

        try {
            $this->classifier->classify($post);
        } catch (ClassificationException $exception) {
            return Response::error("Classification failed for post {$post->id}: {$exception->getMessage()}");
        }

        $post->refresh()->loadMissing('profile');

        return Response::structured([
            'post' => [
                'id' => $post->id,
                'author' => $post->author,
                'username' => $post->profile->username,
                'text' => $post->text,
                'url' => $post->url,
                'published_at' => $post->published_at->toIso8601String(),
                'classification' => [
                    'status' => $post->classification_status,
                    'relevant' => $post->classification_relevant,
                    'score' => $post->classification_score,
                    'category' => $post->classification_category,
                    'content_value_score' => $post->classification_content_value_score,
                    'adaptability_score' => $post->classification_adaptability_score,
                    'fits_profile' => $post->classification_profile_fit,
                    'requires_missing_media' => $post->classification_requires_missing_media,
                    'classified_at' => $post->classified_at?->toIso8601String(),
                ],
            ],
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
            'post_id' => $schema->integer()
                ->description('The stored post id to classify.')
                ->min(1),
        ];
    }
}
