<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Story>
 */
class StoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('now', '+3 months');
        $endDate = fake()->dateTimeBetween($startDate, '+6 months');

        $storyTitles = [
            'Design API endpoints',
            'Implement authentication flow',
            'Create database migrations',
            'Build frontend components',
            'Write integration tests',
            'Update documentation',
            'Performance optimization',
            'Security audit',
            'Code refactoring',
            'Deploy to staging',
        ];

        return [
            'epic_id' => \App\Models\Epic::factory(),
            'squad_id' => \App\Models\Squad::factory(),
            'status_id' => \App\Models\Status::inRandomOrder()->first()?->id ?? \App\Models\Status::factory(),
            'title' => fake()->randomElement($storyTitles),
            'description' => fake()->paragraph(),
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    }
}
