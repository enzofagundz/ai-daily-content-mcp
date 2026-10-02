<?php

namespace Tests\Support;

use App\Services\Classification\ClassificationException;
use App\Services\Classification\ContentClassification;
use App\Services\Classification\ContentClassifier;
use App\Services\Classification\PostClassificationInput;

class FakeContentClassifier implements ContentClassifier
{
    /**
     * @var list<PostClassificationInput>
     */
    public array $calls = [];

    public function __construct(
        private ?ContentClassification $result = null,
        private ?ClassificationException $exception = null,
    ) {}

    public function classify(PostClassificationInput $post): ContentClassification
    {
        $this->calls[] = $post;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->result ??= new ContentClassification(
            relevanceProbability: 0.9,
            category: 'developer_tools',
            contentValueScore: 3.0,
            adaptabilityScore: 3.0,
            profileFitProbability: 0.9,
            missingMediaProbability: 0.1,
        );
    }
}
