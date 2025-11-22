<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QuarterPlan>
 */
class QuarterPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = now();
        $year = $now->year;
        $quarter = ceil($now->month / 3);

        return [
            'team_id' => \App\Models\Team::factory(),
            'squad_id' => \App\Models\Squad::factory(),
            'year' => $year,
            'quarter' => $quarter,
            'available_story_points' => fake()->numberBetween(50, 200),
        ];
    }
}
