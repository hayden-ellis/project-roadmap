<?php

namespace Database\Factories;

use App\Models\Epic;
use App\Models\Squad;
use App\Support\Quarter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EpicQuarterPlan>
 */
class EpicQuarterPlanFactory extends Factory
{
    public function definition(): array
    {
        $quarter = Quarter::current();

        return [
            'epic_id' => Epic::factory(),
            'squad_id' => Squad::factory(),
            'year' => $quarter->year,
            'quarter' => $quarter->quarter,
            'planned_points' => fake()->numberBetween(20, 120),
            'delivered_points' => null,
            'sort_order' => 0,
        ];
    }
}
