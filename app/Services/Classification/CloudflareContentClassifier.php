<?php

namespace App\Services\Classification;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class CloudflareContentClassifier implements ContentClassifier
{
    /**
     * Question ids mapped to their Clef type.
     *
     * @var array<string, string>
     */
    private const QUESTIONS = [
        'relevant' => 'noul',
        'category' => 'choice',
        'content_value' => 'score',
        'linkedin_adaptability' => 'score',
        'fits_profile' => 'noul',
        'requires_missing_media' => 'noul',
    ];

    /**
     * Tolerance when checking that a probability set sums to 1.
     */
    private const PROBABILITY_TOLERANCE = 0.01;

    public function __construct(
        private string $accountId,
        private string $apiToken,
        private string $model,
        private string $baseUrl,
        private int $timeout,
    ) {}

    public function classify(PostClassificationInput $post): ContentClassification
    {
        $answers = $this->answers($post);

        return new ContentClassification(
            relevanceProbability: $this->noulProbability($answers, 'relevant'),
            category: $this->choice($answers, 'category'),
            contentValueScore: $this->score($answers, 'content_value'),
            adaptabilityScore: $this->score($answers, 'linkedin_adaptability'),
            profileFitProbability: $this->noulProbability($answers, 'fits_profile'),
            missingMediaProbability: $this->noulProbability($answers, 'requires_missing_media'),
        );
    }

    /**
     * Send the state and questions to Workers AI and return the validated answers.
     *
     * @return array<string, mixed>
     *
     * @throws ClassificationException
     */
    private function answers(PostClassificationInput $post): array
    {
        try {
            $response = Http::withToken($this->apiToken)
                ->timeout($this->timeout)
                ->acceptJson()
                ->post($this->endpoint(), [
                    'model' => Str::afterLast($this->model, '/'),
                    'state' => $this->state($post),
                    'questions' => $this->questions(),
                ]);
        } catch (ConnectionException $exception) {
            throw new ClassificationException(
                'The classification request failed: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        if ($response->failed()) {
            throw new ClassificationException("Cloudflare Workers AI returned HTTP {$response->status()}.");
        }

        $json = $response->json();
        $result = is_array($json) ? ($json['result'] ?? null) : null;

        if (! is_array($json) || ($json['success'] ?? false) !== true || ! is_array($result)) {
            throw new ClassificationException('Cloudflare Workers AI returned an unsuccessful response.');
        }

        $answers = $result['answers'] ?? null;

        if (! is_array($answers)) {
            throw new ClassificationException('The classifier response is missing its answers.');
        }

        foreach (self::QUESTIONS as $id => $type) {
            $answer = $answers[$id] ?? null;

            if (! is_array($answer) || ($answer['type'] ?? null) !== $type) {
                throw new ClassificationException("The classifier response is missing a valid \"{$id}\" answer.");
            }
        }

        return $answers;
    }

    private function endpoint(): string
    {
        return sprintf('%s/accounts/%s/ai/run/%s', rtrim($this->baseUrl, '/'), $this->accountId, $this->model);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(PostClassificationInput $post): array
    {
        $editorial = (array) config('content_classifier.editorial');

        return [
            'post' => [
                'author' => $post->author,
                'username' => $post->username,
                'content' => $post->text,
                'url' => $post->url,
                'published_at' => $post->publishedAt->toIso8601String(),
            ],
            'editorial_context' => [
                'audience' => $editorial['audience'] ?? '',
                'topics' => $editorial['topics'] ?? [],
                'prioritize' => $editorial['prioritize'] ?? [],
                'avoid' => $editorial['avoid'] ?? [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function questions(): array
    {
        $instructions = (array) config('content_classifier.questions');
        $categories = (array) config('content_classifier.categories');
        $scores = (array) config('content_classifier.scores');

        return [
            'relevant' => [
                'type' => 'noul',
                'instructions' => $instructions['relevant'] ?? '',
            ],
            'category' => [
                'type' => 'choice',
                'instructions' => $instructions['category'] ?? '',
                'criteria' => $categories,
            ],
            'content_value' => [
                'type' => 'score',
                'instructions' => $instructions['content_value'] ?? '',
                'criteria' => array_values((array) ($scores['content_value'] ?? [])),
            ],
            'linkedin_adaptability' => [
                'type' => 'score',
                'instructions' => $instructions['linkedin_adaptability'] ?? '',
                'criteria' => array_values((array) ($scores['linkedin_adaptability'] ?? [])),
            ],
            'fits_profile' => [
                'type' => 'noul',
                'instructions' => $instructions['fits_profile'] ?? '',
            ],
            'requires_missing_media' => [
                'type' => 'noul',
                'instructions' => $instructions['requires_missing_media'] ?? '',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function noulProbability(array $answers, string $id): float
    {
        $answer = $answers[$id] ?? null;
        $probability = is_array($answer) ? ($answer['noul'] ?? null) : null;

        if (! is_numeric($probability) || (float) $probability < 0 || (float) $probability > 1) {
            throw new ClassificationException("The classifier response has an invalid \"{$id}\" probability.");
        }

        return (float) $probability;
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function choice(array $answers, string $id): string
    {
        $answer = $answers[$id] ?? null;

        if (! is_array($answer)) {
            throw new ClassificationException("The classifier response has an invalid \"{$id}\" answer.");
        }

        $allowed = array_map(strval(...), array_keys((array) config('content_classifier.categories')));
        $chosen = $answer['choice'] ?? null;

        if (! is_string($chosen) || ! in_array($chosen, $allowed, true)) {
            throw new ClassificationException("The classifier response has an invalid \"{$id}\" choice.");
        }

        $this->assertProbabilities($answer['probabilities'] ?? null, $allowed, $id);
        $this->assertConfidence($answer['confidence'] ?? null, $id);

        return $chosen;
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function score(array $answers, string $id): float
    {
        $answer = $answers[$id] ?? null;

        if (! is_array($answer)) {
            throw new ClassificationException("The classifier response has an invalid \"{$id}\" answer.");
        }

        $levels = array_map(strval(...), array_keys((array) config("content_classifier.scores.{$id}")));
        $score = $answer['score'] ?? null;
        $maximum = count($levels) - 1;

        if (! is_numeric($score) || (float) $score < 0 || (float) $score > $maximum) {
            throw new ClassificationException("The classifier response has an invalid \"{$id}\" score.");
        }

        $this->assertProbabilities($answer['probabilities'] ?? null, $levels, $id);
        $this->assertConfidence($answer['confidence'] ?? null, $id);

        return (float) $score;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertProbabilities(mixed $probabilities, array $allowed, string $id): void
    {
        if (! is_array($probabilities) || $probabilities === []) {
            throw new ClassificationException("The classifier response is missing the \"{$id}\" probabilities.");
        }

        $sum = 0.0;

        foreach ($probabilities as $level => $probability) {
            if (! in_array((string) $level, $allowed, true)
                || ! is_numeric($probability)
                || (float) $probability < 0
                || (float) $probability > 1) {
                throw new ClassificationException("The classifier response has invalid \"{$id}\" probabilities.");
            }

            $sum += (float) $probability;
        }

        if (abs($sum - 1.0) > self::PROBABILITY_TOLERANCE) {
            throw new ClassificationException("The \"{$id}\" probabilities do not sum to 1.");
        }
    }

    private function assertConfidence(mixed $confidence, string $id): void
    {
        if (! is_numeric($confidence) || (float) $confidence < 0 || (float) $confidence > 1) {
            throw new ClassificationException("The classifier response has an invalid \"{$id}\" confidence.");
        }
    }
}
