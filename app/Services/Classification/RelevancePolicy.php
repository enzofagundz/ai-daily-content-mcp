<?php

namespace App\Services\Classification;

/**
 * Deterministic relevance rule: the classifier provides the signals,
 * the application combines them through configurable thresholds.
 */
final readonly class RelevancePolicy
{
    public function __construct(
        private float $relevanceThreshold,
        private float $profileFitThreshold,
        private float $minimumContentValue,
        private float $minimumAdaptability,
        private float $missingMediaThreshold,
    ) {}

    public function isRelevant(ContentClassification $classification): bool
    {
        return $classification->relevanceProbability >= $this->relevanceThreshold
            && $classification->profileFitProbability >= $this->profileFitThreshold
            && $classification->contentValueScore >= $this->minimumContentValue
            && $classification->adaptabilityScore >= $this->minimumAdaptability
            && $classification->missingMediaProbability < $this->missingMediaThreshold;
    }

    public function fitsProfile(ContentClassification $classification): bool
    {
        return $classification->profileFitProbability >= $this->profileFitThreshold;
    }

    public function requiresMissingMedia(ContentClassification $classification): bool
    {
        return $classification->missingMediaProbability >= $this->missingMediaThreshold;
    }
}
