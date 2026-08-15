<?php

use App\Models\Allocation;
use App\Models\Engineer;
use App\Models\Epic;
use App\Models\EpicPause;
use App\Models\EpicQuarterPlan;
use App\Models\Squad;
use App\Models\Status;
use App\Models\User;
use App\Support\Quarter;
use Livewire\Livewire;

/**
 * The board is what people say. The grid is what is happening. Most of what
 * follows is about the seam between the two.
 */
beforeEach(function () {
    $user = User::factory()->withPersonalTeam()->create();
    $this->team = $user->currentTeam;
    $this->team->update(['week_starts_on' => 2]);
    $this->squad = Squad::create(['team_id' => $this->team->id, 'name' => 'Charging', 'color' => '#EF4444']);
    $this->currentWeek = $this->team->weekCalendar()->current();

    $make = fn (array $attributes) => Status::create([
        'team_id' => $this->team->id,
        'color' => '#71717A',
    ] + $attributes);

    $this->backlog = $make(['name' => 'Backlog', 'is_default' => true]);
    $this->doing = $make(['name' => 'In progress']);
    $this->paused = $make(['name' => 'Paused', 'requires_reason' => true]);
    $this->shipped = $make(['name' => 'Shipped', 'is_complete' => true]);

    $this->makeEpic = fn (string $title, ?Status $status = null) => Epic::create([
        'team_id' => $this->team->id,
        'title' => $title,
        'status_id' => ($status ?? $this->backlog)->id,
    ]);

    $this->engineerFor = fn () => Engineer::create([
        'team_id' => $this->team->id,
        'squad_id' => $this->squad->id,
        'name' => fake()->name(),
        'default_weekly_points' => 10,
    ]);

    $this->staff = function (Epic $epic, $week = null) {
        $engineer = ($this->engineerFor)();

        Allocation::create([
            'engineer_id' => $engineer->id,
            'epic_id' => $epic->id,
            'week_start' => $week ?? $this->currentWeek,
        ]);

        return $engineer;
    };

    $this->foreignEpic = function () {
        $otherUser = User::factory()->withPersonalTeam()->create();

        return Epic::create([
            'team_id' => $otherUser->currentTeam->id,
            'title' => 'Their work',
        ]);
    };

    $this->actingAs($user);
});

/** @return \Illuminate\Support\Collection<int, Epic> */
function column($component, Status $status)
{
    return collect($component->viewData('columns'))
        ->firstWhere(fn ($c) => $c['status']->id === $status->id)['epics'];
}

it('builds a column for every status, in the order they are set', function () {
    Livewire::test('now')->assertViewHas(
        'columns',
        fn ($columns) => collect($columns)->pluck('status.name')->all()
            === ['Backlog', 'In progress', 'Paused', 'Shipped'],
    );
});

it('files each epic in the column it was put in', function () {
    $queued = ($this->makeEpic)('Wallet Top-ups');
    $working = ($this->makeEpic)('Checkout Redesign', $this->doing);
    $done = ($this->makeEpic)('Data Warehouse Migration', $this->shipped);

    $component = Livewire::test('now');

    expect(column($component, $this->backlog)->pluck('id'))->toContain($queued->id)
        ->and(column($component, $this->doing)->pluck('id'))->toContain($working->id)
        ->and(column($component, $this->shipped)->pluck('id'))->toContain($done->id);
});

it('shows who is on an epic this week', function () {
    $epic = ($this->makeEpic)('Checkout Redesign', $this->doing);
    $engineer = ($this->staff)($epic);

    Livewire::test('now')->assertSee($engineer->initials());
});

describe('filtering by squad', function () {
    beforeEach(function () {
        $quarter = Quarter::current();

        $this->planInto = fn (Epic $epic, Squad $squad) => EpicQuarterPlan::create([
            'epic_id' => $epic->id,
            'squad_id' => $squad->id,
            'year' => $quarter->year,
            'quarter' => $quarter->quarter,
        ]);

        $this->billing = Squad::create(['team_id' => $this->team->id, 'name' => 'Billing', 'color' => '#3B82F6']);
    });

    it('narrows the board to epics planned into the chosen squad', function () {
        $ours = ($this->makeEpic)('Wallet Top-ups');
        $theirs = ($this->makeEpic)('Invoice Export');
        $floating = ($this->makeEpic)('Unplanned Idea');
        ($this->planInto)($ours, $this->squad);
        ($this->planInto)($theirs, $this->billing);

        $component = Livewire::test('now')->set('squadFilter', (string) $this->squad->id);

        expect(column($component, $this->backlog)->pluck('id'))
            ->toContain($ours->id)
            ->not->toContain($theirs->id)
            ->not->toContain($floating->id);
    });

    it('keeps a multi-squad epic visible under either of its squads', function () {
        $shared = ($this->makeEpic)('Payments Platform');
        ($this->planInto)($shared, $this->squad);
        ($this->planInto)($shared, $this->billing);

        foreach ([$this->squad, $this->billing] as $squad) {
            $component = Livewire::test('now')->set('squadFilter', (string) $squad->id);

            expect(column($component, $this->backlog)->pluck('id'))->toContain($shared->id);
        }
    });

    it('shows only unplanned epics under "no squad"', function () {
        $planned = ($this->makeEpic)('Wallet Top-ups');
        $floating = ($this->makeEpic)('Unplanned Idea');
        ($this->planInto)($planned, $this->squad);

        $component = Livewire::test('now')->set('squadFilter', 'none');

        expect(column($component, $this->backlog)->pluck('id'))
            ->toContain($floating->id)
            ->not->toContain($planned->id);
    });

    it('falls back to everything when the remembered squad no longer exists', function () {
        $epic = ($this->makeEpic)('Wallet Top-ups');

        $component = Livewire::test('now')->set('squadFilter', '999999');

        expect(column($component, $this->backlog)->pluck('id'))->toContain($epic->id)
            ->and($component->get('squadFilter'))->toBe('');
    });

    it('drops a dragged card relative to visible cards, not hidden ones', function () {
        $hidden = ($this->makeEpic)('Invoice Export', $this->doing);
        $alpha = ($this->makeEpic)('Alpha', $this->doing);
        $bravo = ($this->makeEpic)('Bravo', $this->doing);
        ($this->planInto)($hidden, $this->billing);
        ($this->planInto)($alpha, $this->squad);
        ($this->planInto)($bravo, $this->squad);

        // The board shows [Alpha, Bravo]; dropping Bravo at position 0 puts
        // it before Alpha, leaving the hidden card's spot alone.
        Livewire::test('now')
            ->set('squadFilter', (string) $this->squad->id)
            ->call('moveEpic', $bravo->id, 0, $this->doing->id);

        expect($this->doing->epics()->onBoard()->pluck('id')->all())
            ->toBe([$hidden->id, $bravo->id, $alpha->id]);
    });
});

describe('dragging between columns', function () {
    it('moves an epic to the dropped column', function () {
        $epic = ($this->makeEpic)('Wallet Top-ups');

        Livewire::test('now')->call('moveEpic', $epic->id, 0, $this->doing->id);

        expect($epic->fresh()->status_id)->toBe($this->doing->id);
    });

    it('reorders within a column without changing status', function () {
        $first = ($this->makeEpic)('Alpha', $this->doing);
        $second = ($this->makeEpic)('Bravo', $this->doing);
        $third = ($this->makeEpic)('Charlie', $this->doing);

        Livewire::test('now')->call('moveEpic', $third->id, 0, $this->doing->id);

        expect(Epic::where('status_id', $this->doing->id)->onBoard()->pluck('title')->all())
            ->toBe(['Charlie', 'Alpha', 'Bravo'])
            ->and($third->fresh()->status_id)->toBe($this->doing->id);
    });

    it('asks why when work lands in a column that wants a reason', function () {
        $epic = ($this->makeEpic)('PSD2 Compliance', $this->doing);

        Livewire::test('now')
            ->call('moveEpic', $epic->id, 0, $this->paused->id)
            ->assertSet('showFlyout', true)
            ->assertSet('panel', 'pause')
            ->assertSet('openEpicId', $epic->id);
    });

    it('does not ask why when the column does not want one', function () {
        $epic = ($this->makeEpic)('Wallet Top-ups');

        Livewire::test('now')
            ->call('moveEpic', $epic->id, 0, $this->doing->id)
            ->assertSet('panel', null)
            ->assertSet('showFlyout', false);
    });

    it('closes an open pause when the epic leaves that column', function () {
        $epic = ($this->makeEpic)('PSD2 Compliance', $this->paused);

        $pause = EpicPause::create([
            'epic_id' => $epic->id,
            'paused_at' => $this->currentWeek->subWeeks(2),
            'reason' => 'Blocked on vendor certification',
        ]);

        Livewire::test('now')->call('moveEpic', $epic->id, 0, $this->doing->id);

        expect($pause->fresh()->resumed_at)->not->toBeNull();
    });

    it('refuses a status belonging to another team', function () {
        $epic = ($this->makeEpic)('Wallet Top-ups');
        $otherUser = User::factory()->withPersonalTeam()->create();
        $foreign = Status::create(['team_id' => $otherUser->currentTeam->id, 'name' => 'Theirs', 'color' => '#000']);

        Livewire::test('now')->call('moveEpic', $epic->id, 0, $foreign->id)->assertForbidden();

        expect($epic->fresh()->status_id)->toBe($this->backlog->id);
    });

    it('refuses to move another team epic', function () {
        $foreign = ($this->foreignEpic)();

        Livewire::test('now')->call('moveEpic', $foreign->id, 0, $this->doing->id)->assertForbidden();
    });
});

describe('flagging what does not match the grid', function () {
    // The flag surfaces only as a chip on the card itself, so these read the
    // epics off the columns rather than a dedicated view collection.
    $boardEpics = fn ($columns) => $columns->flatMap(fn ($column) => $column['epics']);

    it('leaves stalled in-progress work unflagged', function () use ($boardEpics) {
        // The quiet-weeks callout was removed -- a working column with nobody
        // booked this week carries no decoration.
        $epic = ($this->makeEpic)('Checkout Redesign', $this->doing);
        ($this->staff)($epic, $this->currentWeek->subWeeks(4));

        Livewire::test('now')
            ->assertViewHas('columns', fn ($c) => $boardEpics($c)->firstWhere('id', $epic->id)?->flag === null)
            ->assertDontSee('Quiet 4w');
    });

    it('leaves an untouched backlog item alone', function () use ($boardEpics) {
        // Never staffed is not the same as stalled -- both have zero weeks.
        ($this->makeEpic)('Wallet Top-ups');

        Livewire::test('now')
            ->assertViewHas('columns', fn ($c) => $boardEpics($c)->every(fn ($e) => $e->flag === null));
    });

    it('leaves actively staffed work alone', function () use ($boardEpics) {
        $epic = ($this->makeEpic)('Checkout Redesign', $this->doing);
        ($this->staff)($epic);

        Livewire::test('now')
            ->assertViewHas('columns', fn ($c) => $boardEpics($c)->every(fn ($e) => $e->flag === null));
    });

    it('flags a finished epic that people are still booked on', function () use ($boardEpics) {
        $epic = ($this->makeEpic)('Data Warehouse Migration', $this->shipped);
        ($this->staff)($epic);

        Livewire::test('now')
            ->assertViewHas('columns', fn ($c) => $boardEpics($c)->firstWhere('id', $epic->id)?->flag !== null)
            ->assertSee('Still booked');
    });

    it('leaves a paused epic unflagged', function () use ($boardEpics) {
        // The column already says paused; the card does not repeat it.
        $epic = ($this->makeEpic)('PSD2 Compliance', $this->paused);

        Livewire::test('now')
            ->assertViewHas('columns', fn ($c) => $boardEpics($c)->every(fn ($e) => $e->flag === null));
    });
});

describe('the flyout', function () {
    it('opens when a card is clicked', function () {
        $epic = ($this->makeEpic)('Checkout Redesign', $this->doing);

        Livewire::test('now')
            ->call('open', $epic->id)
            ->assertSet('showFlyout', true)
            ->assertSet('openEpicId', $epic->id)
            ->assertSet('editTitle', 'Checkout Redesign')
            ->assertSee('Nobody is booked on this yet');
    });

    /**
     * Guards the bug this replaced: flux:modal has no `open` prop, so
     * `:open="..."` rendered a dialog that could never be shown.
     */
    it('binds the flyout to a property flux actually reads', function () {
        $html = Livewire::test('now')->html();

        expect($html)->toMatch('/wire:model(\.self)?="showFlyout"/');
    });

    it('clears the open epic when dismissed', function () {
        $epic = ($this->makeEpic)('Checkout Redesign', $this->doing);

        Livewire::test('now')
            ->call('open', $epic->id)
            ->set('showFlyout', false)   // what Esc / click-outside does
            ->assertSet('openEpicId', null)
            ->assertSet('panel', null);
    });

    it('refuses to open another team epic', function () {
        Livewire::test('now')->call('open', ($this->foreignEpic)()->id)->assertForbidden();
    });
});

describe('editing in the flyout', function () {
    it('writes a title change straight to the epic', function () {
        $epic = ($this->makeEpic)('Checkout Redesign');

        Livewire::test('now')
            ->call('open', $epic->id)
            ->set('editTitle', 'Checkout Redesign V2');

        expect($epic->fresh()->title)->toBe('Checkout Redesign V2');
    });

    it('refuses an empty title and leaves the epic alone', function () {
        $epic = ($this->makeEpic)('Checkout Redesign');

        Livewire::test('now')
            ->call('open', $epic->id)
            ->set('editTitle', '')
            ->assertHasErrors('editTitle');

        expect($epic->fresh()->title)->toBe('Checkout Redesign');
    });

    it('writes a description straight to the epic, and empties it back to null', function () {
        $epic = ($this->makeEpic)('Checkout Redesign');

        $component = Livewire::test('now')
            ->call('open', $epic->id)
            ->set('editDescription', 'One basket, one payment.');

        expect($epic->fresh()->description)->toBe('One basket, one payment.');

        $component->set('editDescription', '');

        expect($epic->fresh()->description)->toBeNull();
    });

    it('writes planned points onto this quarter plan', function () {
        $epic = ($this->makeEpic)('Checkout Redesign');
        $quarter = Quarter::current();

        $plan = EpicQuarterPlan::create([
            'epic_id' => $epic->id,
            'squad_id' => $this->squad->id,
            'year' => $quarter->year,
            'quarter' => $quarter->quarter,
        ]);

        Livewire::test('now')
            ->call('open', $epic->id)
            ->set('editPlannedPoints', 25);

        expect($plan->fresh()->planned_points)->toBe(25);
    });

    it('does nothing with points when the epic has no plan this quarter', function () {
        $epic = ($this->makeEpic)('Checkout Redesign');

        Livewire::test('now')
            ->call('open', $epic->id)
            ->set('editPlannedPoints', 25);

        expect($epic->quarterPlans()->count())->toBe(0);
    });

    it('seeds a new plan with the points already on screen', function () {
        $epic = ($this->makeEpic)('Checkout Redesign');
        $quarter = Quarter::current();

        Livewire::test('now')
            ->call('open', $epic->id)
            ->set('editPlannedPoints', 15)
            ->set('editSquadId', (string) $this->squad->id);

        expect($epic->quarterPlans()->forQuarter($quarter)->first()->planned_points)->toBe(15);
    });

    it('writes category and priority changes straight to the epic', function () {
        $category = App\Models\Category::create([
            'team_id' => $this->team->id, 'name' => 'Reliability', 'color' => '#F59E0B',
        ]);
        $epic = ($this->makeEpic)('Checkout Redesign');

        Livewire::test('now')
            ->call('open', $epic->id)
            ->set('editCategoryId', (string) $category->id)
            ->set('editPriority', 'critical');

        expect($epic->fresh())
            ->category_id->toBe($category->id)
            ->priority->toBe('critical');
    });

    it('refuses a category belonging to another team', function () {
        $otherUser = User::factory()->withPersonalTeam()->create();
        $foreign = App\Models\Category::create([
            'team_id' => $otherUser->currentTeam->id, 'name' => 'Theirs', 'color' => '#000000',
        ]);
        $epic = ($this->makeEpic)('Checkout Redesign');

        Livewire::test('now')
            ->call('open', $epic->id)
            ->set('editCategoryId', (string) $foreign->id)
            ->assertForbidden();

        expect($epic->fresh()->category_id)->toBeNull();
    });

    it('assigns a squad by creating a plan for the current quarter', function () {
        $epic = ($this->makeEpic)('Checkout Redesign');
        $quarter = Quarter::current();

        Livewire::test('now')
            ->call('open', $epic->id)
            ->set('editSquadId', (string) $this->squad->id);

        expect($epic->quarterPlans()->forQuarter($quarter)->first())
            ->not->toBeNull()
            ->squad_id->toBe($this->squad->id);
    });

    it('carries the planned points over when the squad is swapped', function () {
        $other = Squad::create(['team_id' => $this->team->id, 'name' => 'Payments', 'color' => '#3B82F6']);
        $epic = ($this->makeEpic)('Checkout Redesign');
        $quarter = Quarter::current();

        EpicQuarterPlan::create([
            'epic_id' => $epic->id,
            'squad_id' => $this->squad->id,
            'year' => $quarter->year,
            'quarter' => $quarter->quarter,
            'planned_points' => 40,
        ]);

        Livewire::test('now')
            ->call('open', $epic->id)
            ->assertSet('editSquadId', (string) $this->squad->id)
            ->set('editSquadId', (string) $other->id);

        $plans = $epic->quarterPlans()->forQuarter($quarter)->get();

        expect($plans)->toHaveCount(1)
            ->and($plans->first())
            ->squad_id->toBe($other->id)
            ->planned_points->toBe(40);
    });

    it('drops this quarter plan when the squad is cleared', function () {
        $epic = ($this->makeEpic)('Checkout Redesign');
        $quarter = Quarter::current();

        EpicQuarterPlan::create([
            'epic_id' => $epic->id,
            'squad_id' => $this->squad->id,
            'year' => $quarter->year,
            'quarter' => $quarter->quarter,
        ]);

        Livewire::test('now')
            ->call('open', $epic->id)
            ->set('editSquadId', '');

        expect($epic->quarterPlans()->forQuarter($quarter)->exists())->toBeFalse();
    });

    it('refuses a squad belonging to another team', function () {
        $otherUser = User::factory()->withPersonalTeam()->create();
        $foreign = Squad::create(['team_id' => $otherUser->currentTeam->id, 'name' => 'Theirs', 'color' => '#000000']);
        $epic = ($this->makeEpic)('Checkout Redesign');

        Livewire::test('now')
            ->call('open', $epic->id)
            ->set('editSquadId', (string) $foreign->id)
            ->assertForbidden();

        expect($epic->quarterPlans()->exists())->toBeFalse();
    });
});

describe('quick-creating an epic', function () {
    beforeEach(function () {
        $this->category = App\Models\Category::create([
            'team_id' => $this->team->id, 'name' => 'Reliability', 'color' => '#F59E0B',
        ]);
    });

    it('lands in the default status when nothing is chosen', function () {
        Livewire::test('now')
            ->call('newEpic')
            ->assertSet('newStatusId', (string) $this->backlog->id)
            ->set('newTitle', 'Charger Fault Telemetry')
            ->set('newCategoryId', (string) $this->category->id)
            ->set('newPriority', 'high')
            ->set('newSquadId', (string) $this->squad->id)
            ->set('newPlannedPoints', 60)
            ->call('createEpic')
            ->assertHasNoErrors();

        $epic = Epic::where('title', 'Charger Fault Telemetry')->firstOrFail();

        expect($epic->status_id)->toBe($this->backlog->id)
            ->and($epic->priority)->toBe('high');

        $this->assertDatabaseHas('epic_quarter_plans', [
            'epic_id' => $epic->id,
            'squad_id' => $this->squad->id,
            'planned_points' => 60,
        ]);
    });

    it('opens prefilled from an empty column with the squad filter carried over', function () {
        Livewire::test('now')
            ->set('squadFilter', (string) $this->squad->id)
            ->call('newEpic', $this->paused->id)
            ->assertSet('newStatusId', (string) $this->paused->id)
            ->assertSet('newSquadId', (string) $this->squad->id);
    });

    it('ignores the no-squad filter when prefilling', function () {
        Livewire::test('now')
            ->set('squadFilter', 'none')
            ->call('newEpic')
            ->assertSet('newSquadId', '');
    });

    it('refuses to open into another team\'s column', function () {
        $otherUser = User::factory()->withPersonalTeam()->create();
        $foreignStatus = Status::create([
            'team_id' => $otherUser->currentTeam->id, 'name' => 'Theirs', 'color' => '#000',
        ]);

        Livewire::test('now')
            ->call('newEpic', $foreignStatus->id)
            ->assertForbidden();
    });

    it('can be created straight into any column', function () {
        Livewire::test('now')
            ->call('newEpic')
            ->set('newTitle', 'Wallet Top-ups')
            ->set('newStatusId', (string) $this->doing->id)
            ->call('createEpic')
            ->assertHasNoErrors();

        expect(Epic::where('title', 'Wallet Top-ups')->value('status_id'))->toBe($this->doing->id);
    });

    it('asks why when created straight into a column that wants a reason', function () {
        Livewire::test('now')
            ->call('newEpic')
            ->set('newTitle', 'Wallet Top-ups')
            ->set('newStatusId', (string) $this->paused->id)
            ->call('createEpic')
            ->assertSet('panel', 'pause');
    });

    it('needs a name', function () {
        Livewire::test('now')
            ->call('newEpic')
            ->set('newTitle', '')
            ->call('createEpic')
            ->assertHasErrors('newTitle');

        expect(Epic::count())->toBe(0);
    });

    it('creates without a squad when one is not chosen', function () {
        Livewire::test('now')
            ->call('newEpic')
            ->set('newTitle', 'Floating idea')
            ->set('newSquadId', '')
            ->call('createEpic')
            ->assertHasNoErrors();

        expect(Epic::where('title', 'Floating idea')->firstOrFail()->quarterPlans)->toBeEmpty();
    });

    it('refuses a squad belonging to another team', function () {
        $otherUser = User::factory()->withPersonalTeam()->create();
        $foreignSquad = Squad::create([
            'team_id' => $otherUser->currentTeam->id, 'name' => 'Theirs', 'color' => '#000',
        ]);

        Livewire::test('now')
            ->call('newEpic')
            ->set('newTitle', 'Sneaky')
            ->set('newSquadId', (string) $foreignSquad->id)
            ->call('createEpic');

        expect(Epic::where('title', 'Sneaky')->firstOrFail()->quarterPlans)->toBeEmpty();
    });
});

describe('work actions', function () {
    it('books a person through the end of the quarter without moving the card', function () {
        $epic = ($this->makeEpic)('Wallet Top-ups');
        $engineer = ($this->engineerFor)();

        Livewire::test('now')
            ->call('open', $epic->id)
            ->call('assign', $engineer->id)
            ->assertHasNoErrors();

        $expected = collect($this->team->weekCalendar()->weeksIn(Quarter::current()))
            ->filter(fn ($week) => $week->gte($this->currentWeek))
            ->count();

        expect(Allocation::where('epic_id', $epic->id)->where('engineer_id', $engineer->id)->count())
            ->toBe($expected)
            ->and($epic->fresh()->status_id)->toBe($this->backlog->id);
    });

    it('refuses an engineer belonging to another team', function () {
        $epic = ($this->makeEpic)('Wallet Top-ups');
        $otherUser = User::factory()->withPersonalTeam()->create();
        $foreign = Engineer::create([
            'team_id' => $otherUser->currentTeam->id,
            'name' => 'Their Person',
            'default_weekly_points' => 10,
        ]);

        Livewire::test('now')
            ->call('open', $epic->id)
            ->call('assign', $foreign->id)
            ->assertForbidden();

        expect(Allocation::count())->toBe(0);
    });

    it('pausing clears upcoming bookings, keeps the past, and files it as paused', function () {
        $epic = ($this->makeEpic)('Checkout Redesign', $this->doing);
        $engineer = ($this->engineerFor)();

        foreach ([-2, -1, 0, 1, 2] as $offset) {
            Allocation::create([
                'engineer_id' => $engineer->id,
                'epic_id' => $epic->id,
                'week_start' => $offset < 0
                    ? $this->currentWeek->subWeeks(abs($offset))
                    : $this->currentWeek->addWeeks($offset),
            ]);
        }

        Livewire::test('now')
            ->call('open', $epic->id)
            ->set('pauseReason', 'Traded for the scheduler')
            ->call('pauseWork')
            ->assertHasNoErrors();

        // History survives; the forward commitment does not.
        expect(Allocation::where('epic_id', $epic->id)->count())->toBe(2)
            ->and(Allocation::where('week_start', '>=', $this->currentWeek->toDateString())->count())->toBe(0)
            ->and($epic->fresh()->status_id)->toBe($this->paused->id);

        $this->assertDatabaseHas('epic_pauses', [
            'epic_id' => $epic->id,
            'reason' => 'Traded for the scheduler',
            'paused_at' => $this->currentWeek->toDateString(),
        ]);
    });

    it('requires a reason when pausing', function () {
        $epic = ($this->makeEpic)('PSD2 Compliance', $this->doing);

        Livewire::test('now')
            ->call('open', $epic->id)
            ->set('pauseReason', '')
            ->call('pauseWork')
            ->assertHasErrors('pauseReason');

        expect(EpicPause::count())->toBe(0);
    });

    it('backdates the pause to when work actually stopped', function () {
        $epic = ($this->makeEpic)('PSD2 Compliance', $this->doing);
        ($this->staff)($epic, $this->currentWeek->subWeeks(3));

        Livewire::test('now')
            ->call('explain', $epic->id)
            ->set('pauseReason', 'Deprioritised')
            ->call('recordPause');

        // Quiet for 3 weeks, so it stopped 2 weeks before the current one.
        expect(EpicPause::first()->paused_at->toDateString())
            ->toBe($this->currentWeek->subWeeks(2)->toDateString());
    });

    it('takes one person off without touching the rest of the crew', function () {
        $epic = ($this->makeEpic)('Checkout Redesign', $this->doing);
        $leaving = ($this->engineerFor)();
        $staying = ($this->engineerFor)();

        foreach ([$leaving, $staying] as $engineer) {
            Allocation::create([
                'engineer_id' => $engineer->id,
                'epic_id' => $epic->id,
                'week_start' => $this->currentWeek,
            ]);
        }

        Livewire::test('now')->call('open', $epic->id)->call('unstaff', $leaving->id);

        expect(Allocation::where('engineer_id', $leaving->id)->count())->toBe(0)
            ->and(Allocation::where('engineer_id', $staying->id)->count())->toBe(1);
    });

    it('marks an epic shipped into the finished column and closes any pause', function () {
        $epic = ($this->makeEpic)('Data Warehouse Migration', $this->doing);

        $pause = EpicPause::create([
            'epic_id' => $epic->id,
            'paused_at' => $this->currentWeek->subWeeks(2),
            'reason' => 'Blocked',
        ]);

        Livewire::test('now')->call('open', $epic->id)->call('markShipped');

        expect($epic->fresh()->status_id)->toBe($this->shipped->id)
            ->and($pause->fresh()->resumed_at)->not->toBeNull();
    });

    it('reopens a shipped epic into the first unfinished column', function () {
        $epic = ($this->makeEpic)('Data Warehouse Migration', $this->shipped);

        Livewire::test('now')->call('open', $epic->id)->call('reopen');

        expect($epic->fresh()->status_id)->toBe($this->backlog->id);
    });

    it('does not surface a pause once the card sits in a working column', function () {
        // A record left open after the card moved on is history, not the
        // present: the flyout must not keep saying "Paused".
        $epic = ($this->makeEpic)('Charger Fault Telemetry', $this->doing);

        EpicPause::create([
            'epic_id' => $epic->id,
            'paused_at' => $this->currentWeek->subWeeks(2),
            'reason' => 'Deprioritised for the scheduler launch',
        ]);

        Livewire::test('now')
            ->call('open', $epic->id)
            ->assertViewHas('openEpic', fn ($e) => $e->openPause === null)
            ->assertDontSee('Deprioritised for the scheduler launch');
    });

    it('closes a lingering pause when a shipped epic is reopened', function () {
        $epic = ($this->makeEpic)('Realtime Ingest', $this->shipped);

        $pause = EpicPause::create([
            'epic_id' => $epic->id,
            'paused_at' => $this->currentWeek->subWeeks(3),
            'reason' => 'Deprioritised',
        ]);

        Livewire::test('now')
            ->call('open', $epic->id)
            ->call('reopen');

        expect($pause->fresh()->resumed_at)->not->toBeNull();
    });

    it('closes an open pause when work restarts', function () {
        $epic = ($this->makeEpic)('Realtime Ingest', $this->paused);
        $engineer = ($this->engineerFor)();

        $pause = EpicPause::create([
            'epic_id' => $epic->id,
            'paused_at' => $this->currentWeek->subWeeks(3),
            'reason' => 'Deprioritised',
        ]);

        Livewire::test('now')
            ->call('open', $epic->id)
            ->call('assign', $engineer->id);

        expect($pause->fresh()->resumed_at)->not->toBeNull();
    });

    it('refuses to pause another team epic even if the id is forged', function () {
        Livewire::test('now')
            ->set('openEpicId', ($this->foreignEpic)()->id)
            ->set('pauseReason', 'Nope')
            ->call('pauseWork')
            ->assertForbidden();

        expect(EpicPause::count())->toBe(0);
    });

    it('refuses to staff another team epic even if the id is forged', function () {
        Livewire::test('now')
            ->set('openEpicId', ($this->foreignEpic)()->id)
            ->call('assign', ($this->engineerFor)()->id)
            ->assertForbidden();

        expect(Allocation::count())->toBe(0);
    });
});

it('points at the statuses page when no columns exist', function () {
    $this->team->statuses()->delete();

    Livewire::test('now')
        ->assertSee('No columns yet')
        ->assertSee('Set up statuses');
});
