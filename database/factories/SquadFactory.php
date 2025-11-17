<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Squad>
 */
class SquadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $squadNames = ['Charging', 'Pricing', 'Payments', 'Billing', 'Analytics', 'Platform', 'Infrastructure'];
        $colors = ['#EF4444', '#F59E0B', '#10B981', '#3B82F6', '#6366F1', '#8B5CF6', '#EC4899'];

        $name = fake()->randomElement($squadNames);

        return [
            'team_id' => \App\Models\Team::factory(),
            'name' => $name,
            'description' => fake()->sentence(),
            'color' => fake()->randomElement($colors),
        ];
    }
}
