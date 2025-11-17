<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Status>
 */
class StatusFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['Not Started', 'In Progress', 'Completed', 'Blocked', 'On Hold']);

        return [
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'color' => fake()->hexColor(),
            'order' => fake()->numberBetween(0, 100),
        ];
    }
}
