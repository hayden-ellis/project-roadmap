<?php

namespace Database\Factories;

use App\Models\Engineer;
use App\Support\Quarter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EngineerQuarterCapacity>
 */
class EngineerQuarterCapacityFactory extends Factory
{
    public function definition(): array
    {
        $quarter = Quarter::current();

        return [
            'engineer_id' => Engineer::factory(),
            'year' => $quarter->year,
            'quarter' => $quarter->quarter,
            'available_points' => 130,
        ];
    }
}
