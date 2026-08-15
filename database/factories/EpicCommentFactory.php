<?php

namespace Database\Factories;

use App\Models\Epic;
use App\Models\EpicComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EpicComment>
 */
class EpicCommentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'epic_id' => Epic::factory(),
            'user_id' => User::factory(),
            'parent_id' => null,
            'body' => fake()->sentence(12),
        ];
    }

    /** A reply lives on the same epic as its root -- anything else is a lie. */
    public function replyTo(EpicComment $root): static
    {
        return $this->state([
            'epic_id' => $root->epic_id,
            'parent_id' => $root->id,
        ]);
    }
}
