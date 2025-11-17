<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Epic>
 */
class EpicFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('now', '+6 months');
        $endDate = fake()->dateTimeBetween($startDate, '+12 months');

        $epicTitles = [
            'Implement Payment Gateway Integration',
            'Build Customer Billing Dashboard',
            'Migrate Legacy Pricing System',
            'Develop Real-time Analytics Engine',
            'Launch Mobile Payment Support',
            'Enhance Security & Compliance',
            'Optimize Charging Infrastructure',
        ];

        return [
            'team_id' => \App\Models\Team::factory(),
            'status_id' => \App\Models\Status::inRandomOrder()->first()?->id ?? \App\Models\Status::factory(),
            'title' => fake()->randomElement($epicTitles),
            'description' => fake()->paragraph(),
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    }
}
