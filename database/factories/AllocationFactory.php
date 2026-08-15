<?php

namespace Database\Factories;

use App\Models\Engineer;
use App\Models\Epic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Allocation>
 */
class AllocationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'engineer_id' => Engineer::factory(),
            'epic_id' => Epic::factory(),
            'week_start' => now()->startOfWeek()->addDay(), // Tuesday
            'share' => 1.0,
        ];
    }
}
