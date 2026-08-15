<?php

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Engineer>
 */
class EngineerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'squad_id' => null,
            'user_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'title' => fake()->randomElement(['Engineer', 'Senior Engineer', 'Staff Engineer']),
            'default_weekly_points' => 10,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
