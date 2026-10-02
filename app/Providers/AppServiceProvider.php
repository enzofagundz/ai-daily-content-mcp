<?php

namespace App\Providers;

use App\Services\Classification\CloudflareContentClassifier;
use App\Services\Classification\ContentClassifier;
use App\Services\Classification\RelevancePolicy;
use App\Services\Twitter\PostSource;
use App\Services\Twitter\TwscrapePostSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PostSource::class, fn (): TwscrapePostSource => new TwscrapePostSource(
            python: (string) config('services.scraper.python'),
            script: (string) config('services.scraper.script'),
            accountsDb: (string) config('services.scraper.accounts_db'),
            timeout: (int) config('services.scraper.timeout'),
        ));

        $this->app->singleton(ContentClassifier::class, fn (): CloudflareContentClassifier => new CloudflareContentClassifier(
            accountId: (string) config('content_classifier.cloudflare.account_id'),
            apiToken: (string) config('content_classifier.cloudflare.api_token'),
            model: (string) config('content_classifier.cloudflare.model'),
            baseUrl: (string) config('content_classifier.cloudflare.base_url'),
            timeout: (int) config('content_classifier.cloudflare.timeout'),
        ));

        $this->app->singleton(RelevancePolicy::class, fn (): RelevancePolicy => new RelevancePolicy(
            relevanceThreshold: (float) config('content_classifier.thresholds.relevance'),
            profileFitThreshold: (float) config('content_classifier.thresholds.profile_fit'),
            minimumContentValue: (float) config('content_classifier.thresholds.content_value'),
            minimumAdaptability: (float) config('content_classifier.thresholds.adaptability'),
            missingMediaThreshold: (float) config('content_classifier.thresholds.missing_media'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
