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
}
