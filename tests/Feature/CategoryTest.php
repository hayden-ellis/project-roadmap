<?php

use App\Models\Category;
use App\Models\Epic;
use App\Models\QuarterPlan;
use App\Models\Squad;
use App\Models\Status;
use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\StatusSeeder::class);

    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->status = Status::first();

    $this->actingAs($this->user);
});

// Model Tests

it('belongs to a team', function () {
    $category = Category::factory()->for($this->team)->create();

    expect($category->team->id)->toBe($this->team->id);
});

it('has many epics', function () {
    $category = Category::factory()->for($this->team)->create();

    $epic1 = Epic::factory()->for($this->team)->for($this->status)->for($category)->create();
    $epic2 = Epic::factory()->for($this->team)->for($this->status)->for($category)->create();

    expect($category->epics)->toHaveCount(2)
        ->and($category->epics->pluck('id')->toArray())->toContain($epic1->id, $epic2->id);
});

it('can belong to quarter plans with allocated story points', function () {
    $category = Category::factory()->for($this->team)->create();
    $squad = Squad::factory()->for($this->team)->create();

    $quarterPlan = QuarterPlan::factory()
        ->for($this->team)
        ->for($squad)
        ->create([
            'year' => 2026,
            'quarter' => 1,
            'available_story_points' => 100,
        ]);

    $quarterPlan->categories()->attach($category->id, ['allocated_story_points' => 40]);

    expect($category->quarterPlans)->toHaveCount(1)
        ->and($category->quarterPlans->first()->pivot->allocated_story_points)->toBe(40);
});

it('has default scope', function () {
    $defaultCategory = Category::factory()->for($this->team)->default()->create();
    $regularCategory = Category::factory()->for($this->team)->create();

    $defaults = Category::default()->get();

    expect($defaults)->toHaveCount(1)
        ->and($defaults->first()->id)->toBe($defaultCategory->id)
        ->and($defaults->first()->is_default)->toBeTrue();
});

it('has ordered scope', function () {
    $category3 = Category::factory()->for($this->team)->create(['name' => 'Category C', 'sort_order' => 3]);
    $category1 = Category::factory()->for($this->team)->create(['name' => 'Category A', 'sort_order' => 1]);
    $category2 = Category::factory()->for($this->team)->create(['name' => 'Category B', 'sort_order' => 2]);

    $ordered = $this->team->categories()->ordered()->get();

    expect($ordered->first()->sort_order)->toBe(1)
        ->and($ordered->get(1)->sort_order)->toBe(2)
        ->and($ordered->last()->sort_order)->toBe(3);
});

// Factory Tests

it('can create category with factory', function () {
    $category = Category::factory()->for($this->team)->create([
        'name' => 'Growth',
        'color' => '#10B981',
    ]);

    expect($category->name)->toBe('Growth')
        ->and($category->color)->toBe('#10B981')
        ->and($category->team_id)->toBe($this->team->id);
});

it('can create default category with factory', function () {
    $category = Category::factory()->for($this->team)->default()->create();

    expect($category->name)->toBe('General')
        ->and($category->is_default)->toBeTrue()
        ->and($category->sort_order)->toBe(0);
});

// Epic Category Relationship Tests

it('epic can have a category', function () {
    $category = Category::factory()->for($this->team)->create(['name' => 'Growth']);

    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->for($category)
        ->create();

    expect($epic->category->id)->toBe($category->id)
        ->and($epic->category->name)->toBe('Growth');
});

it('epic can have no category', function () {
    $epic = Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->create(['category_id' => null]);

    expect($epic->category)->toBeNull();
});

it('category epics are scoped by team', function () {
    $otherTeam = Team::factory()->create();
    $category = Category::factory()->for($this->team)->create(['name' => 'Growth']);
    $otherCategory = Category::factory()->for($otherTeam)->create(['name' => 'Growth']);

    Epic::factory()
        ->for($this->team)
        ->for($this->status)
        ->for($category)
        ->create();

    Epic::factory()
        ->for($otherTeam)
        ->for($this->status)
        ->for($otherCategory)
        ->create();

    // The team category should only have one epic
    expect($category->epics)->toHaveCount(1);
    expect($otherCategory->epics)->toHaveCount(1);
});

// Casting Tests

it('casts sort_order to integer', function () {
    $category = Category::factory()->for($this->team)->create(['sort_order' => '5']);

    expect($category->sort_order)->toBe(5)
        ->and($category->sort_order)->toBeInt();
});

it('casts is_default to boolean', function () {
    $category = Category::factory()->for($this->team)->create(['is_default' => 1]);

    expect($category->is_default)->toBeTrue()
        ->and($category->is_default)->toBeBool();
});
