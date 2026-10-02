<?php

namespace App\Services\Classification;

use App\Models\Post;
use Carbon\CarbonImmutable;

/**
 * Classifies a stored post and persists the structured result.
 */
final readonly class PostClassifier
{
    public function __construct(
        private ContentClassifier $classifier,
        private RelevancePolicy $policy,
    ) {}

    /**
     * @throws ClassificationException when the provider cannot classify the post.
     */
    public function classify(Post $post): void
    {
        $post->loadMissing('profile');

        try {
            $classification = $this->classifier->classify(new PostClassificationInput(
                author: $post->author,
                username: $post->profile->username,
                text: $post->text,
                url: $post->url,
                publishedAt: CarbonImmutable::parse($post->published_at),
            ));
        } catch (ClassificationException $exception) {
            // A failed run keeps any previous signals; consumers must filter by status.
            $post->forceFill([
                'classification_status' => Post::CLASSIFICATION_FAILED,
                'classification_error' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }

        $post->forceFill([
            'classification_status' => Post::CLASSIFICATION_CLASSIFIED,
            'classification_relevant' => $this->policy->isRelevant($classification),
            'classification_score' => $classification->relevanceProbability,
            'classification_category' => $classification->category,
            'classification_content_value_score' => $classification->contentValueScore,
            'classification_adaptability_score' => $classification->adaptabilityScore,
            'classification_profile_fit' => $this->policy->fitsProfile($classification),
            'classification_requires_missing_media' => $this->policy->requiresMissingMedia($classification),
            'classified_at' => now(),
            'classification_error' => null,
        ])->save();
    }
}
