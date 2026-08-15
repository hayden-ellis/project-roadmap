<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

/**
 * Every authenticated route renders against realistic seeded data.
 *
 * Cheap insurance during the refactor: catches a view still referencing a
 * dropped model long before anyone clicks through the app.
 */
beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->user = User::where('email', 'hre0001@outlook.com')->firstOrFail();
});

dataset('routes', [
    'now' => '/now',
    'epics index' => '/epics',
    'epics create' => '/epics/create',
    'squads index' => '/squads',
    'squads create' => '/squads/create',
    'statuses index' => '/statuses',
    'categories index' => '/categories',
    'categories create' => '/categories/create',
    'engineers index' => '/engineers',
    'engineers create' => '/engineers/create',
    'roadmap calendar' => '/roadmap',
    'roadmap timeline' => '/roadmap/timeline',
    'planning grid' => '/planning',
]);

it('renders', function (string $route) {
    $this->actingAs($this->user)
        ->get($route)
        ->assertOk();
})->with('routes');

it('renders record pages', function () {
    $epic = App\Models\Epic::where('title', 'Charger Fault Telemetry')->firstOrFail();
    $engineer = App\Models\Engineer::where('name', 'Mia Okafor')->firstOrFail();

    $this->actingAs($this->user)->get("/epics/{$epic->id}/edit")->assertOk();
    $this->actingAs($this->user)->get("/engineers/{$engineer->id}/edit")->assertOk();
});
