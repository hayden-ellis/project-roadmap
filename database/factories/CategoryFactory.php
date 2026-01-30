<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $categoryNames = ['General', 'Growth', 'Tech Debt', 'Run the Business', 'Innovation', 'Infrastructure'];
        $colors = ['#6B7280', '#10B981', '#F59E0B', '#3B82F6', '#8B5CF6', '#EF4444'];

        return [
            'team_id' => \App\Models\Team::factory(),
            'name' => fake()->randomElement($categoryNames),
            'description' => fake()->sentence(),
            'color' => fake()->randomElement($colors),
            'sort_order' => fake()->numberBetween(0, 10),
            'is_default' => false,
        ];
    }

    /**
     * Indicate that the category is the default.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'General',
            'is_default' => true,
            'sort_order' => 0,
        ]);
    }
}
