<?php

namespace App\Services\Classification;

interface ContentClassifier
{
    /**
     * Classify a single post for eventual LinkedIn content triage.
     *
     * @throws ClassificationException when the provider cannot return a valid classification.
     */
    public function classify(PostClassificationInput $post): ContentClassification;
}
