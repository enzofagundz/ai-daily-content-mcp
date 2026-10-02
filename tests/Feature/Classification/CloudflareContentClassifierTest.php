<?php

use App\Services\Classification\ClassificationException;
use App\Services\Classification\CloudflareContentClassifier;
use App\Services\Classification\PostClassificationInput;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function cloudflareClassifier(): CloudflareContentClassifier
{
    return new CloudflareContentClassifier(
        accountId: 'account-123',
        apiToken: 'token-abc',
        model: '@cf/cloudflare/clef-flash',
        baseUrl: 'https://api.cloudflare.com/client/v4',
        timeout: 30,
    );
}

function classificationInput(): PostClassificationInput
{
    return new PostClassificationInput(
        author: 'Theo',
        username: 'theo',
        text: 'A practical guide to agentic coding tools.',
        url: 'https://x.com/theo/status/123',
        publishedAt: CarbonImmutable::parse('2026-10-02T10:00:00Z'),
    );
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cloudflareResponse(array $overrides = []): array
{
    return array_replace_recursive([
        'result' => [
            'model' => 'clef-flash',
            'answers' => [
                'relevant' => ['type' => 'noul', 'noul' => 0.87],
                'category' => [
                    'type' => 'choice',
                    'choice' => 'developer_tools',
                    'probabilities' => ['developer_tools' => 0.7, 'ai' => 0.3],
                    'confidence' => 0.9,
                ],
                'content_value' => [
                    'type' => 'score',
                    'score' => 3.2,
                    'legend' => ['0' => 'none', '1' => 'limited', '2' => 'interesting', '3' => 'practical', '4' => 'strong'],
                    'probabilities' => ['0' => 0.05, '1' => 0.1, '2' => 0.2, '3' => 0.4, '4' => 0.25],
                    'confidence' => 0.8,
                ],
                'linkedin_adaptability' => [
                    'type' => 'score',
                    'score' => 3.5,
                    'legend' => ['0' => 'none', '1' => 'low', '2' => 'medium', '3' => 'high', '4' => 'clear'],
                    'probabilities' => ['0' => 0.0, '1' => 0.1, '2' => 0.15, '3' => 0.4, '4' => 0.35],
                    'confidence' => 0.85,
                ],
                'fits_profile' => ['type' => 'noul', 'noul' => 0.91],
                'requires_missing_media' => ['type' => 'noul', 'noul' => 0.12],
            ],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 10],
        ],
        'success' => true,
        'errors' => [],
        'messages' => [],
    ], $overrides);
}

test('rejects a response with a missing answer', function () {
    $response = cloudflareResponse();
    unset($response['result']['answers']['fits_profile']);

    Http::fake(['*' => Http::response($response)]);

    expect(fn () => cloudflareClassifier()->classify(classificationInput()))
        ->toThrow(ClassificationException::class, 'fits_profile');
});

test('rejects a response with the wrong answer type', function () {
    Http::fake(['*' => Http::response(cloudflareResponse([
        'result' => ['answers' => ['relevant' => ['type' => 'score', 'score' => 1.0]]],
    ]))]);

    expect(fn () => cloudflareClassifier()->classify(classificationInput()))
        ->toThrow(ClassificationException::class);
});

test('rejects a category outside the allowed options', function () {
    Http::fake(['*' => Http::response(cloudflareResponse([
        'result' => ['answers' => ['category' => ['choice' => 'politics']]],
    ]))]);

    expect(fn () => cloudflareClassifier()->classify(classificationInput()))
        ->toThrow(ClassificationException::class, 'category');
});

test('rejects a score outside the configured levels', function () {
    Http::fake(['*' => Http::response(cloudflareResponse([
        'result' => ['answers' => ['content_value' => ['score' => 7.5]]],
    ]))]);

    expect(fn () => cloudflareClassifier()->classify(classificationInput()))
        ->toThrow(ClassificationException::class, 'content_value');
});

test('rejects an out of range probability', function () {
    Http::fake(['*' => Http::response(cloudflareResponse([
        'result' => ['answers' => ['relevant' => ['noul' => 1.4]]],
    ]))]);

    expect(fn () => cloudflareClassifier()->classify(classificationInput()))
        ->toThrow(ClassificationException::class, 'relevant');
});

test('rejects probabilities that do not sum to one', function () {
    Http::fake(['*' => Http::response(cloudflareResponse([
        'result' => ['answers' => ['category' => ['probabilities' => ['developer_tools' => 0.5, 'ai' => 0.3]]]],
    ]))]);

    expect(fn () => cloudflareClassifier()->classify(classificationInput()))
        ->toThrow(ClassificationException::class, 'sum to 1');
});

test('rejects an unsuccessful API envelope', function () {
    Http::fake(['*' => Http::response(cloudflareResponse(['success' => false]))]);

    expect(fn () => cloudflareClassifier()->classify(classificationInput()))
        ->toThrow(ClassificationException::class, 'unsuccessful');
});

test('reports an API error response', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    expect(fn () => cloudflareClassifier()->classify(classificationInput()))
        ->toThrow(ClassificationException::class, 'HTTP 500');
});

test('reports a timeout as a classification failure', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    expect(fn () => cloudflareClassifier()->classify(classificationInput()))
        ->toThrow(ClassificationException::class, 'timed out');
});

test('classifies a post through the Clef decision schema', function () {
    Http::fake(['*' => Http::response(cloudflareResponse())]);

    $classification = cloudflareClassifier()->classify(classificationInput());

    expect($classification->relevanceProbability)->toBe(0.87)
        ->and($classification->category)->toBe('developer_tools')
        ->and($classification->contentValueScore)->toBe(3.2)
        ->and($classification->adaptabilityScore)->toBe(3.5)
        ->and($classification->profileFitProbability)->toBe(0.91)
        ->and($classification->missingMediaProbability)->toBe(0.12);

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->url() === 'https://api.cloudflare.com/client/v4/accounts/account-123/ai/run/@cf/cloudflare/clef-flash'
            && $request->hasHeader('Authorization', 'Bearer token-abc')
            && $body['model'] === 'clef-flash'
            && $body['state']['post']['author'] === 'Theo'
            && $body['state']['post']['username'] === 'theo'
            && $body['state']['post']['content'] === 'A practical guide to agentic coding tools.'
            && $body['state']['post']['published_at'] === '2026-10-02T10:00:00+00:00'
            && $body['state']['editorial_context']['audience'] === 'desenvolvedor brasileiro no LinkedIn'
            && in_array('Laravel/PHP', $body['state']['editorial_context']['topics'], true)
            && $body['questions']['relevant']['type'] === 'noul'
            && $body['questions']['category']['type'] === 'choice'
            && array_key_exists('developer_tools', $body['questions']['category']['criteria'])
            && $body['questions']['content_value']['type'] === 'score'
            && count($body['questions']['content_value']['criteria']) === 5
            && $body['questions']['linkedin_adaptability']['type'] === 'score'
            && $body['questions']['fits_profile']['type'] === 'noul'
            && $body['questions']['requires_missing_media']['type'] === 'noul';
    });
});
