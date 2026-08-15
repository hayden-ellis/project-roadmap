<?php

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Status>
 */
class StatusFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->unique()->randomElement([
                'Backlog', 'Up next', 'In progress', 'In review', 'Paused', 'Shipped',
            ]),
            'color' => fake()->randomElement(['#71717A', '#10B981', '#F59E0B', '#3B82F6', '#8B5CF6']),
            'description' => null,
            'is_default' => false,
            'is_complete' => false,
            'requires_reason' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }

    public function complete(): static
    {
        return $this->state(['is_complete' => true]);
    }

    public function asksWhy(): static
    {
        return $this->state(['requires_reason' => true]);
    }
}
