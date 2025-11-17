<?php

namespace Database\Seeders;

use App\Models\Status;
use Illuminate\Database\Seeder;

class StatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $statuses = [
            ['name' => 'Not Started', 'slug' => 'not-started', 'color' => '#9CA3AF', 'order' => 1],
            ['name' => 'In Progress', 'slug' => 'in-progress', 'color' => '#3B82F6', 'order' => 2],
            ['name' => 'Completed', 'slug' => 'completed', 'color' => '#10B981', 'order' => 3],
            ['name' => 'Blocked', 'slug' => 'blocked', 'color' => '#EF4444', 'order' => 4],
        ];

        foreach ($statuses as $status) {
            Status::firstOrCreate(
                ['slug' => $status['slug']],
                $status
            );
        }
    }
}
