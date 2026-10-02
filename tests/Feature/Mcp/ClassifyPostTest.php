<?php

use App\Mcp\Servers\DailyContentServer;
use App\Mcp\Tools\ClassifyPost;
use App\Models\Post;
use App\Services\Classification\ClassificationException;
use Illuminate\Testing\Fluent\AssertableJson;

test('classify_post classifies a post and persists the result', function () {
    $post = Post::factory()->create(['text' => 'A post about agentic coding tools.']);

    $fake = fakeClassifier(classificationSignals(
        relevanceProbability: 0.88,
        contentValueScore: 3.2,
        adaptabilityScore: 3.5,
    ));

    DailyContentServer::tool(ClassifyPost::class, ['post_id' => $post->id])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($post) {
            $json->where('post.id', $post->id)
                ->where('post.text', 'A post about agentic coding tools.')
                ->where('post.classification.status', 'classified')
                ->where('post.classification.relevant', true)
                ->where('post.classification.score', 0.88)
                ->where('post.classification.category', 'developer_tools')
                ->where('post.classification.content_value_score', 3.2)
                ->where('post.classification.adaptability_score', 3.5)
                ->where('post.classification.fits_profile', true)
                ->where('post.classification.requires_missing_media', false);
        });

    expect($fake->calls)->toHaveCount(1)
        ->and($fake->calls[0]->text)->toBe('A post about agentic coding tools.');

    $post->refresh();

    expect($post->classification_status)->toBe('classified')
        ->and($post->classification_relevant)->toBeTrue()
        ->and($post->classification_score)->toBe(0.88)
        ->and($post->classification_category)->toBe('developer_tools')
        ->and($post->classification_content_value_score)->toBe(3.2)
        ->and($post->classification_adaptability_score)->toBe(3.5)
        ->and($post->classification_profile_fit)->toBeTrue()
        ->and($post->classification_requires_missing_media)->toBeFalse()
        ->and($post->classified_at)->not->toBeNull()
        ->and($post->classification_error)->toBeNull()
        ->and($post->text)->toBe('A post about agentic coding tools.');
});

test('classify_post reclassifies an already classified post on request', function () {
    $post = Post::factory()->classified()->create();

    $fake = fakeClassifier(classificationSignals(relevanceProbability: 0.95));

    DailyContentServer::tool(ClassifyPost::class, ['post_id' => $post->id])->assertOk();

    expect($fake->calls)->toHaveCount(1)
        ->and($post->refresh()->classification_score)->toBe(0.95);
});

test('classify_post keeps the post stored and reports the failure when the classifier fails', function () {
    $post = Post::factory()->create();

    fakeClassifier(exception: new ClassificationException('Cloudflare Workers AI returned HTTP 500.'));

    DailyContentServer::tool(ClassifyPost::class, ['post_id' => $post->id])
        ->assertHasErrors(['HTTP 500']);

    $post->refresh();

    expect($post->classification_status)->toBe('failed')
        ->and($post->classification_error)->toBe('Cloudflare Workers AI returned HTTP 500.')
        ->and($post->classification_relevant)->toBeNull();
});

test('classify_post fails when the post does not exist', function () {
    fakeClassifier();

    DailyContentServer::tool(ClassifyPost::class, ['post_id' => 999])
        ->assertHasErrors(['was not found']);
});

test('classify_post requires a post id', function () {
    fakeClassifier();

    DailyContentServer::tool(ClassifyPost::class, [])->assertHasErrors();
});

test('the relevance verdict follows the configured thresholds', function (
    float $relevance,
    float $profileFit,
    float $contentValue,
    float $adaptability,
    float $missingMedia,
    bool $expected,
) {
    $post = Post::factory()->create();

    fakeClassifier(classificationSignals(
        relevanceProbability: $relevance,
        contentValueScore: $contentValue,
        adaptabilityScore: $adaptability,
        profileFitProbability: $profileFit,
        missingMediaProbability: $missingMedia,
    ));

    DailyContentServer::tool(ClassifyPost::class, ['post_id' => $post->id])->assertOk();

    expect($post->refresh()->classification_relevant)->toBe($expected);
})->with([
    'all signals strong' => [0.9, 0.9, 3.0, 3.0, 0.1, true],
    'relevance below threshold' => [0.5, 0.9, 3.0, 3.0, 0.1, false],
    'profile fit below threshold' => [0.9, 0.5, 3.0, 3.0, 0.1, false],
    'content value below minimum' => [0.9, 0.9, 1.5, 3.0, 0.1, false],
    'adaptability below minimum' => [0.9, 0.9, 3.0, 1.0, 0.1, false],
    'depends on missing media' => [0.9, 0.9, 3.0, 3.0, 0.8, false],
]);
