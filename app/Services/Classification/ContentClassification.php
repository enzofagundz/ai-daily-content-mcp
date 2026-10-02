<?php

namespace App\Services\Classification;

/**
 * Provider-independent classification signals for a single post.
 *
 * The relevance verdict is not stored here: it is produced by the
 * application from these signals through the RelevancePolicy.
 */
final readonly class ContentClassification
{
    public function __construct(
        public float $relevanceProbability,
        public string $category,
        public float $contentValueScore,
        public float $adaptabilityScore,
        public float $profileFitProbability,
        public float $missingMediaProbability,
    ) {}
}
