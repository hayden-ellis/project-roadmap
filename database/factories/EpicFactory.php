<?php

namespace Database\Factories;

use App\Models\Status;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Epic>
 */
class EpicFactory extends Factory
{
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('-2 months', '+4 months');

        return [
            'team_id' => Team::factory(),
            'category_id' => null,
            'title' => fake()->unique()->randomElement([
                'Implement Payment Gateway Integration',
                'Build Customer Billing Dashboard',
                'Migrate Legacy Pricing System',
                'Develop Real-time Analytics Engine',
                'Launch Mobile Payment Support',
                'Enhance Security & Compliance',
                'Optimize Charging Infrastructure',
            ]),
            'description' => fake()->paragraph(),
            'status_id' => null,
            'priority' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
            'start_date' => $startDate,
            'end_date' => fake()->dateTimeBetween($startDate, '+12 months'),
            'is_recurring' => false,
        ];
    }

    public function inStatus(Status|int $status): static
    {
        return $this->state(['status_id' => $status instanceof Status ? $status->id : $status]);
    }

    /** Files the epic in a fresh finished status on its own team. */
    public function done(): static
    {
        return $this->afterMaking(function ($epic) {
            $epic->status_id ??= Status::factory()->complete()->create([
                'team_id' => $epic->team_id,
                'name' => 'Shipped',
            ])->id;
        });
    }
}
