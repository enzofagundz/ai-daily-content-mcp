<?php

use App\Services\Classification\ClassificationException;
use App\Services\Classification\ContentClassification;
use App\Services\Classification\ContentClassifier;
use App\Services\Twitter\FetchedPost;
use App\Services\Twitter\PostFetchResult;
use App\Services\Twitter\PostSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeContentClassifier;
use Tests\Support\FakePostSource;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Bind a fake post source in the container and return it for assertions.
 */
function fakeSource(PostFetchResult $result): FakePostSource
{
    $fake = new FakePostSource($result);
    app()->instance(PostSource::class, $fake);

    return $fake;
}

/**
 * Bind a fake content classifier in the container and return it for assertions.
 */
function fakeClassifier(?ContentClassification $result = null, ?ClassificationException $exception = null): FakeContentClassifier
{
    $fake = new FakeContentClassifier($result, $exception);
    app()->instance(ContentClassifier::class, $fake);

    return $fake;
}

/**
 * Build classification signals above the default relevance thresholds.
 */
function classificationSignals(
    float $relevanceProbability = 0.9,
    string $category = 'developer_tools',
    float $contentValueScore = 3.0,
    float $adaptabilityScore = 3.0,
    float $profileFitProbability = 0.9,
    float $missingMediaProbability = 0.1,
): ContentClassification {
    return new ContentClassification(
        relevanceProbability: $relevanceProbability,
        category: $category,
        contentValueScore: $contentValueScore,
        adaptabilityScore: $adaptabilityScore,
        profileFitProbability: $profileFitProbability,
        missingMediaProbability: $missingMediaProbability,
    );
}

/**
 * Build a fetched post with sensible defaults for tests.
 */
function fetchedPost(string $externalId, string $username, string $publishedAt, string $text = 'post text', ?string $author = null): FetchedPost
{
    return new FetchedPost(
        externalId: $externalId,
        username: $username,
        author: $author ?? ucfirst($username),
        text: $text,
        url: "https://x.com/{$username}/status/{$externalId}",
        publishedAt: CarbonImmutable::parse($publishedAt),
    );
}
