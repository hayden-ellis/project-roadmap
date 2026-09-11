<?php

use App\Models\Epic;
use App\Models\EpicComment;
use App\Models\Status;
use App\Models\User;
use App\Notifications\EpicCommented;
use App\Notifications\EpicStatusChanged;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Board cards carry a comment count, tinted when the thread holds a reply or
 * mention you have not read yet. Reading the thread clears the tint.
 */
beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->other = User::factory()->create();

    $this->status = Status::create(['team_id' => $this->team->id, 'name' => 'Building', 'color' => '#22C55E', 'is_default' => true]);
    $this->epic = Epic::create(['team_id' => $this->team->id, 'title' => 'Payments revamp', 'status_id' => $this->status->id]);

    $this->unread = fn (string $type, ?int $epicId = null) => $this->user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => $type,
        'data' => ['epic_id' => $epicId ?? $this->epic->id],
    ]);

    $this->actingAs($this->user);
});

it('counts the comments on the card', function () {
    EpicComment::create(['epic_id' => $this->epic->id, 'user_id' => $this->other->id, 'body' => 'One']);
    EpicComment::create(['epic_id' => $this->epic->id, 'user_id' => $this->user->id, 'body' => 'Two']);

    Livewire::test('now')->assertSee('2 comments')->assertDontSee('new for you');
});

it('shows no count on a card nobody has commented on', function () {
    Livewire::test('now')->assertDontSee('0 comments');
});

it('tints the count when a comment notification for the epic is unread', function () {
    EpicComment::create(['epic_id' => $this->epic->id, 'user_id' => $this->other->id, 'body' => 'Hello?']);
    ($this->unread)(EpicCommented::class);

    Livewire::test('now')->assertSee('1 comment, new for you');
});

it('does not tint for an unread status change', function () {
    EpicComment::create(['epic_id' => $this->epic->id, 'user_id' => $this->other->id, 'body' => 'Hello?']);
    ($this->unread)(EpicStatusChanged::class);

    Livewire::test('now')->assertSee('1 comment')->assertDontSee('new for you');
});

it('clears the tint once the thread has been opened', function () {
    EpicComment::create(['epic_id' => $this->epic->id, 'user_id' => $this->other->id, 'body' => 'Hello?']);
    ($this->unread)(EpicCommented::class);

    $elsewhere = Epic::create(['team_id' => $this->team->id, 'title' => 'Another', 'status_id' => $this->status->id]);
    ($this->unread)(EpicCommented::class, $elsewhere->id);

    // Opening the dialog puts the thread on screen, so opening is reading.
    Livewire::test('now')
        ->assertSee('new for you')
        ->call('open', $this->epic->id)
        ->assertDontSee('1 comment, new for you');

    // Only this epic's thread was read; the other card keeps its tint.
    expect($this->user->unreadNotifications()->count())->toBe(1);
});
