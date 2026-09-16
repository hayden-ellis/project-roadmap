<?php

use App\Models\Category;
use App\Models\Epic;
use App\Models\User;
use Livewire\Livewire;

/**
 * Deleting a category moves its epics to the default in one query, which
 * Eloquent does not announce -- so the page writes their history itself.
 */
beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;

    $this->general = Category::create(['team_id' => $this->team->id, 'name' => 'General', 'is_default' => true]);
    $this->growth = Category::create(['team_id' => $this->team->id, 'name' => 'Growth']);

    $this->actingAs($this->user);
});

it('records the move to the default category for each epic it had', function () {
    $moved = collect(['Referrals', 'Onboarding'])->map(fn ($title) => Epic::create([
        'team_id' => $this->team->id, 'title' => $title, 'category_id' => $this->growth->id,
    ]));
    $untouched = Epic::create(['team_id' => $this->team->id, 'title' => 'Billing', 'category_id' => $this->general->id]);

    Livewire::test('categories.edit', ['category' => $this->growth])
        ->call('delete')
        ->assertRedirect('/categories');

    expect(Category::find($this->growth->id))->toBeNull();

    $moved->each(function (Epic $epic) {
        expect($epic->fresh()->category_id)->toBe($this->general->id);

        $row = $epic->activities()->first();

        expect($row->lines()[0]['text'])->toBe('changed category from Growth to General')
            ->and($row->user_id)->toBe($this->user->id);
    });

    expect($untouched->activities()->count())->toBe(1);
});

it('refuses to delete the default category', function () {
    Livewire::test('categories.edit', ['category' => $this->general])
        ->call('delete');

    expect(Category::find($this->general->id))->not->toBeNull();
});
