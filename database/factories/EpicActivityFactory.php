<?php

namespace Database\Factories;

use App\Models\Epic;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EpicActivity>
 */
class EpicActivityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'epic_id' => Epic::factory(),
            'user_id' => User::factory(),
            'actor_name' => fn (array $attributes) => User::find($attributes['user_id'])?->name,
            'event' => 'updated',
            'source' => 'web',
            'diff' => [
                'title' => ['from' => 'Old title', 'to' => 'New title'],
            ],
        ];
    }

    /** A row written with nobody signed in, the way the seeder does. */
    public function system(): static
    {
        return $this->state([
            'user_id' => null,
            'actor_name' => null,
            'source' => 'system',
        ]);
    }
}
