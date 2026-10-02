<?php

namespace Database\Factories;

use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $username = fake()->unique()->regexify('[a-z][a-z0-9_]{4,11}');

        return [
            'username' => $username,
            'url' => "https://x.com/{$username}",
        ];
    }
}
