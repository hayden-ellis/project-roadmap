<?php

use App\Models\Category;
use App\Models\Epic;
use App\Models\EpicActivity;
use App\Models\Status;
use App\Models\User;
use App\Support\EpicHistory;

/**
 * History is written by the Epic model's hooks, so these tests drive plain
 * Eloquent saves and check what lands: the actor, the labels as they were at
 * the time, and the silence when nothing worth remembering changed.
 */
beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create(['name' => 'Ada Lovelace']);
    $this->team = $this->user->currentTeam;

    $this->backlog = Status::create(['team_id' => $this->team->id, 'name' => 'Backlog', 'is_default' => true]);
    $this->progress = Status::create(['team_id' => $this->team->id, 'name' => 'In progress']);

    $this->product = Category::create(['team_id' => $this->team->id, 'name' => 'Product', 'is_default' => true]);
    $this->platform = Category::create(['team_id' => $this->team->id, 'name' => 'Platform']);

    $this->makeEpic = fn (array $attributes = []) => Epic::create($attributes + [
        'team_id' => $this->team->id,
        'title' => 'Checkout Redesign',
        'status_id' => $this->backlog->id,
        'category_id' => $this->product->id,
        'priority' => 'high',
    ]);

    /** The newest row on an epic, after its creation row. */
    $this->latest = fn (Epic $epic) => $epic->activities()->first();

    $this->actingAs($this->user);
});

it('records the creation with the initial status and category names', function () {
    $epic = ($this->makeEpic)();

    $row = $epic->activities()->sole();

    expect($row->event)->toBe('created')
        ->and($row->user_id)->toBe($this->user->id)
        ->and($row->actor_name)->toBe('Ada Lovelace')
        ->and($row->source)->toBe('web')
        ->and($row->diff['status_id']['to_label'])->toBe('Backlog')
        ->and($row->diff['category_id']['to_label'])->toBe('Product')
        ->and($row->diff['priority']['to'])->toBe('high')
        ->and($row->diff)->not->toHaveKey('description');

    expect(collect($row->lines())->pluck('text')->all())
        ->toBe(['created this epic', 'in Backlog, under Product, at High priority']);
});

it('records a title change with the actor', function () {
    $epic = ($this->makeEpic)();

    $epic->update(['title' => 'Checkout v2']);

    $row = ($this->latest)($epic);

    expect($row->event)->toBe('updated')
        ->and($row->user_id)->toBe($this->user->id)
        ->and($row->diff)->toBe(['title' => ['from' => 'Checkout Redesign', 'to' => 'Checkout v2']])
        ->and($row->lines()[0]['text'])->toBe('renamed it from "Checkout Redesign" to "Checkout v2"');
});

it('stores status names, not just ids, so history survives a rename', function () {
    $epic = ($this->makeEpic)();

    $epic->update(['status_id' => $this->progress->id]);

    $this->progress->update(['name' => 'Doing']);

    $row = ($this->latest)($epic);

    expect($row->diff['status_id'])->toBe([
        'from' => $this->backlog->id,
        'to' => $this->progress->id,
        'from_label' => 'Backlog',
        'to_label' => 'In progress',
    ])->and($row->lines()[0]['text'])->toBe('moved it from Backlog to In progress');
});

it('records a status change made through the shared action', function () {
    $epic = ($this->makeEpic)();

    app(App\Actions\Epics\ChangeEpicStatus::class)->handle($epic, $this->progress);

    expect(($this->latest)($epic)->lines()[0]['text'])->toBe('moved it from Backlog to In progress');
});

it('records nothing when only untracked fields change', function () {
    $epic = ($this->makeEpic)();

    $epic->update(['board_order' => 9, 'matrix_order' => 4, 'importance' => 'high', 'urgency' => 'urgent']);

    expect($epic->activities()->count())->toBe(1);
});

it('records nothing when a tracked field is saved with the value it already had', function () {
    $epic = ($this->makeEpic)(['start_date' => '2026-01-05', 'description' => null]);

    $epic->update(['start_date' => '2026-01-05', 'title' => 'Checkout Redesign']);
    $epic->update(['description' => '']);

    expect($epic->activities()->count())->toBe(1);
});

it('folds several fields saved together into one entry', function () {
    $epic = ($this->makeEpic)();

    $epic->update(['title' => 'Checkout v2', 'priority' => 'critical', 'jira_epic_url' => 'https://acme.atlassian.net/browse/PAY-42']);

    $row = ($this->latest)($epic);

    expect($epic->activities()->count())->toBe(2)
        ->and(array_keys($row->diff))->toBe(['title', 'priority', 'jira_epic_url'])
        ->and(collect($row->lines())->pluck('text')->all())->toBe([
            'renamed it from "Checkout Redesign" to "Checkout v2"',
            'changed priority from High to Critical',
            'linked Jira epic PAY-42',
        ]);
});

it('words each kind of change', function (array $before, array $after, string $expected) {
    $epic = ($this->makeEpic)($before);

    $epic->update($after);

    expect(($this->latest)($epic)->lines()[0]['text'])->toBe($expected);
})->with([
    'priority' => [[], ['priority' => 'low'], 'changed priority from High to Low'],
    'start date set' => [[], ['start_date' => '2026-01-05'], 'set the start date to 5 Jan 2026'],
    'end date moved' => [['end_date' => '2026-03-01'], ['end_date' => '2026-03-15'], 'moved the end date from 1 Mar 2026 to 15 Mar 2026'],
    'end date cleared' => [['end_date' => '2026-03-01'], ['end_date' => null], 'cleared the end date'],
    'recurring on' => [[], ['is_recurring' => true], 'marked it recurring'],
    'recurring off' => [['is_recurring' => true], ['is_recurring' => false], 'marked it one-off'],
    'jira link changed' => [['jira_epic_url' => 'https://acme.atlassian.net/browse/PAY-42'], ['jira_epic_url' => 'https://acme.atlassian.net/browse/PAY-43'], 'changed the Jira epic link to PAY-43'],
    'jpd unlinked' => [['jpd_idea_url' => 'https://acme.atlassian.net/jira/polaris/projects/IDEA/ideas/view/1?selectedIssue=IDEA-7'], ['jpd_idea_url' => null], 'unlinked the JPD idea'],
    'release set' => [[], ['release_percent' => 40], 'set release to 40%'],
    'release moved' => [['release_percent' => 40], ['release_percent' => 100], 'moved release from 40% to 100%'],
    'release cleared' => [['release_percent' => 40], ['release_percent' => null], 'cleared the release %'],
    'description added' => [[], ['description' => 'Rebuild the checkout flow.'], 'added a description'],
    'description cleared' => [['description' => 'Old words.'], ['description' => ''], 'cleared the description'],
    'status cleared' => [[], ['status_id' => null], 'took it out of Backlog'],
]);

it('words a category change by name', function () {
    $epic = ($this->makeEpic)();

    $epic->update(['category_id' => $this->platform->id]);
    expect(($this->latest)($epic)->lines()[0]['text'])->toBe('changed category from Product to Platform');

    $epic->update(['category_id' => null]);
    expect(($this->latest)($epic)->lines()[0]['text'])->toBe('removed the category');

    $epic->update(['category_id' => $this->product->id]);
    expect(($this->latest)($epic)->lines()[0]['text'])->toBe('set category to Product');
});

it('keeps the full description text and marks the line as long', function () {
    $epic = ($this->makeEpic)(['description' => 'Old words.']);

    $epic->update(['description' => 'New words, rather more of them.']);

    $line = ($this->latest)($epic)->lines()[0];

    expect($line)->toMatchArray([
        'text' => 'updated the description',
        'from' => 'Old words.',
        'to' => 'New words, rather more of them.',
        'long' => true,
    ]);
});

it('records a system row when nobody is signed in', function () {
    auth()->logout();

    $epic = ($this->makeEpic)();

    $row = $epic->activities()->sole();

    expect($row->user_id)->toBeNull()
        ->and($row->actor_name)->toBeNull()
        ->and($row->source)->toBe('system')
        ->and($row->actorName())->toBe('System')
        ->and($row->isSystem())->toBeTrue();
});

it('keeps history when the actor\'s account is deleted', function () {
    $teammate = User::factory()->create(['name' => 'Grace Hopper']);
    $this->team->users()->attach($teammate, ['role' => 'admin']);
    $teammate->switchTeam($this->team);

    $epic = ($this->makeEpic)();

    $this->actingAs($teammate);
    $epic->update(['title' => 'Renamed by Grace']);

    $teammate->delete();

    $row = $epic->activities()->first();

    expect($row->user_id)->toBeNull()
        ->and($row->actorName())->toBe('Grace Hopper');
});

it('writes one row per epic for a bulk move', function () {
    $one = ($this->makeEpic)(['title' => 'One']);
    $two = ($this->makeEpic)(['title' => 'Two']);

    EpicHistory::recordBulk(collect([$one, $two]), 'status_id', $this->backlog->id, 'Backlog', null, null);

    expect(($this->latest)($one)->lines()[0]['text'])->toBe('took it out of Backlog')
        ->and(($this->latest)($two)->diff['status_id'])->toBe([
            'from' => $this->backlog->id,
            'to' => null,
            'from_label' => 'Backlog',
            'to_label' => null,
        ])
        ->and(($this->latest)($two)->user_id)->toBe($this->user->id)
        ->and(($this->latest)($two)->source)->toBe('web');
});

it('goes with the epic when it is deleted', function () {
    $epic = ($this->makeEpic)();
    $epic->update(['title' => 'Gone soon']);

    $epic->delete();

    expect(EpicActivity::count())->toBe(0);
});
