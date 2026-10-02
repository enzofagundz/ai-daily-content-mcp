<?php

namespace Database\Factories;

use App\Models\Post;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $externalId = (string) fake()->unique()->numerify('###################');

        return [
            'profile_id' => Profile::factory(),
            'external_id' => $externalId,
            'url' => 'https://x.com/example/status/'.$externalId,
            'text' => fake()->sentence(),
            'author' => fake()->name(),
            'published_at' => fake()->dateTimeBetween('-1 month'),
            'presented_at' => null,
        ];
    }

    public function classified(): static
    {
        return $this->state(fn (): array => [
            'classification_status' => Post::CLASSIFICATION_CLASSIFIED,
            'classification_relevant' => true,
            'classification_score' => 0.9,
            'classification_category' => 'developer_tools',
            'classification_content_value_score' => 3.0,
            'classification_adaptability_score' => 3.0,
            'classification_profile_fit' => true,
            'classification_requires_missing_media' => false,
            'classified_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'classification_status' => Post::CLASSIFICATION_FAILED,
            'classification_error' => 'Cloudflare Workers AI returned HTTP 500.',
        ]);
    }
}
