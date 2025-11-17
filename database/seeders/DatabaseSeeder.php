<?php

namespace Database\Seeders;

use App\Models\Epic;
use App\Models\Squad;
use App\Models\Status;
use App\Models\Story;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(StatusSeeder::class);

        $user = User::factory()->withPersonalTeam()->create([
            'name' => 'Hayden Ellis',
            'email' => 'hre0001@outlook.com',
            'password' => 'password',
        ]);

        $team = $user->currentTeam;

        $squads = collect([
            Squad::factory()->for($team)->create(['name' => 'Charging', 'color' => '#EF4444', 'description' => 'Manages EV charging infrastructure']),
            Squad::factory()->for($team)->create(['name' => 'Pricing', 'color' => '#F59E0B', 'description' => 'Handles pricing strategies and models']),
            Squad::factory()->for($team)->create(['name' => 'Payments', 'color' => '#10B981', 'description' => 'Payment processing and integration']),
            Squad::factory()->for($team)->create(['name' => 'Analytics', 'color' => '#3B82F6', 'description' => 'Data analytics and reporting']),
        ]);

        $statuses = Status::all();

        $priorities = ['low', 'medium', 'high', 'critical'];
        $epics = collect();
        foreach (range(1, 5) as $i) {
            $epic = Epic::factory()
                ->for($team)
                ->create([
                    'status_id' => $statuses->random()->id,
                    'priority' => $priorities[array_rand($priorities)],
                    'start_date' => now()->addDays(rand(-30, 30)),
                    'end_date' => now()->addDays(rand(60, 180)),
                ]);

            // Attach squads with pivot data
            $selectedSquads = $squads->random(rand(1, 3));
            $epicStart = $epic->start_date;
            $epicEnd = $epic->end_date;

            foreach ($selectedSquads as $squad) {
                // Each squad gets a different time slice within the epic's timeframe
                $squadStart = $epicStart->copy()->addDays(rand(0, 15));
                $squadEnd = $epicEnd->copy()->subDays(rand(0, 15));

                $epic->squads()->attach($squad->id, [
                    'start_date' => $squadStart,
                    'end_date' => $squadEnd,
                    'story_points' => rand(3, 21),
                ]);
            }

            $epics->push($epic);
        }

        foreach ($epics as $epic) {
            foreach ($epic->squads as $squad) {
                Story::factory()
                    ->count(rand(2, 5))
                    ->for($epic)
                    ->for($squad)
                    ->create([
                        'status_id' => $statuses->random()->id,
                        'start_date' => $epic->start_date?->addDays(rand(0, 15)),
                        'end_date' => $epic->end_date?->subDays(rand(0, 15)),
                    ]);
            }
        }
    }
}
