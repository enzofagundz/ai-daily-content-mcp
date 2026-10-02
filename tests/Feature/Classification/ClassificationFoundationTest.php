<?php

use App\Models\Post;
use Carbon\CarbonInterface;

test('new posts start with a pending classification', function () {
    $post = Post::factory()->create();
    $post->refresh();

    expect($post->classification_status)->toBe('pending')
        ->and($post->classification_relevant)->toBeNull()
        ->and($post->classification_score)->toBeNull()
        ->and($post->classification_category)->toBeNull()
        ->and($post->classification_content_value_score)->toBeNull()
        ->and($post->classification_adaptability_score)->toBeNull()
        ->and($post->classification_profile_fit)->toBeNull()
        ->and($post->classification_requires_missing_media)->toBeNull()
        ->and($post->classified_at)->toBeNull()
        ->and($post->classification_error)->toBeNull();
});

test('classification results are stored with typed casts', function () {
    $post = Post::factory()->create();

    $post->forceFill([
        'classification_status' => 'classified',
        'classification_relevant' => true,
        'classification_score' => 0.87,
        'classification_category' => 'developer_tools',
        'classification_content_value_score' => 3.2,
        'classification_adaptability_score' => 3.5,
        'classification_profile_fit' => true,
        'classification_requires_missing_media' => false,
        'classified_at' => now(),
    ])->save();

    $post->refresh();

    expect($post->classification_status)->toBe('classified')
        ->and($post->classification_relevant)->toBeTrue()
        ->and($post->classification_score)->toBe(0.87)
        ->and($post->classification_category)->toBe('developer_tools')
        ->and($post->classification_content_value_score)->toBe(3.2)
        ->and($post->classification_adaptability_score)->toBe(3.5)
        ->and($post->classification_profile_fit)->toBeTrue()
        ->and($post->classification_requires_missing_media)->toBeFalse()
        ->and($post->classified_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($post->classification_error)->toBeNull();
});

test('classification config centralizes model, thresholds and editorial context', function () {
    $config = config('content_classifier');

    expect($config['cloudflare']['model'])->toBe(env('CLOUDFLARE_AI_MODEL', '@cf/cloudflare/clef-flash'))
        ->and($config['cloudflare']['timeout'])->toBe((int) env('CONTENT_CLASSIFIER_TIMEOUT', 30))
        ->and($config['thresholds'])->toBe([
            'relevance' => (float) env('CONTENT_CLASSIFIER_RELEVANCE_THRESHOLD', 0.70),
            'profile_fit' => (float) env('CONTENT_CLASSIFIER_PROFILE_THRESHOLD', 0.70),
            'content_value' => (float) env('CONTENT_CLASSIFIER_MIN_VALUE_SCORE', 2.0),
            'adaptability' => (float) env('CONTENT_CLASSIFIER_MIN_ADAPTABILITY_SCORE', 2.0),
            'missing_media' => (float) env('CONTENT_CLASSIFIER_MISSING_MEDIA_THRESHOLD', 0.50),
        ])
        ->and($config['editorial']['topics'])->toHaveCount(8)
        ->and($config['editorial']['prioritize'])->not->toBeEmpty()
        ->and($config['editorial']['avoid'])->not->toBeEmpty()
        ->and($config['questions'])->toHaveKeys(['relevant', 'fits_profile', 'requires_missing_media'])
        ->and($config['categories'])->toHaveKeys([
            'ai',
            'programming',
            'software_engineering',
            'developer_tools',
            'web_development',
            'career',
            'product',
            'other',
        ])
        ->and($config['scores']['content_value'])->toHaveCount(5)
        ->and($config['scores']['linkedin_adaptability'])->toHaveCount(5);
});
