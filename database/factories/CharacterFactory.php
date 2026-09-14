<?php

namespace Database\Factories;

use App\Models\Character;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Character>
 */
class CharacterFactory extends Factory
{
    protected $model = Character::class;

    public function definition(): array
    {
        return [
            'character_id' => fake()->unique()->numberBetween(90_000_000, 2_100_000_000),
            'name' => fake()->userName(),
            'owner_hash' => fake()->sha1(),
            'scopes' => ['esi-skills.read_skills.v1'],
            'access_token' => 'access-'.fake()->sha1(),
            'access_token_expires_at' => now()->addMinutes(20),
            'refresh_token' => 'refresh-'.fake()->sha1(),
        ];
    }

    public function withExpiredAccessToken(): static
    {
        return $this->state(fn () => ['access_token_expires_at' => now()->subMinute()]);
    }
}
