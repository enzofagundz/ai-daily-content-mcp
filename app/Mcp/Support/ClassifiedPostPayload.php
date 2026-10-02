<?php

namespace App\Mcp\Support;

use App\Models\Post;

final class ClassifiedPostPayload
{
    /**
     * Shape a stored post and its classification for MCP responses.
     *
     * @return array<string, mixed>
     */
    public static function for(Post $post): array
    {
        return [
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
        ];
    }
}
