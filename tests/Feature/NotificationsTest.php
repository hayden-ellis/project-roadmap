<?php

use App\Models\Epic;
use App\Models\EpicComment;
use App\Models\Status;
use App\Models\User;
use App\Notifications\EpicCommented;
use App\Notifications\EpicStatusChanged;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Commenting is how somebody opts into an epic's conversation; the bell
 * and the emails follow from that, never from mere team membership.
 */
beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->other = User::factory()->create();

    $this->epic = Epic::create(['team_id' => $this->team->id, 'title' => 'Payments revamp']);

    $this->commentAs = fn (User $user, string $body = 'Earlier thoughts.') => EpicComment::create([
        'epic_id' => $this->epic->id,
        'user_id' => $user->id,
        'body' => $body,
    ]);

    $this->actingAs($this->user);
});

it('notifies the other participants when a comment is posted', function () {
    Notification::fake();

    ($this->commentAs)($this->other);

    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->set('commentBody', 'What is the latest here?')
        ->call('addComment');

    Notification::assertSentTo($this->other, EpicCommented::class);
    Notification::assertNotSentTo($this->user, EpicCommented::class);
});

it('notifies nobody when the epic has no other participants', function () {
    Notification::fake();

    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->set('commentBody', 'Talking to myself.')
        ->call('addComment');

    Notification::assertNothingSent();
});

it('sends the comment notification by database and mail', function () {
    $comment = ($this->commentAs)($this->user, 'A remark.');

    expect((new EpicCommented($comment))->via($this->other))->toBe(['database', 'mail']);
});

it('notifies participants when the status changes, however it changes', function () {
    Notification::fake();

    $doing = Status::create(['team_id' => $this->team->id, 'name' => 'Doing', 'color' => '#22C55E']);

    ($this->commentAs)($this->other);

    // A bare update, as the board drag and the pause actions perform it.
    $this->epic->update(['status_id' => $doing->id]);

    Notification::assertSentTo($this->other, EpicStatusChanged::class, fn ($notification) => $notification->to === 'Doing' && $notification->actor === $this->user->name);
    Notification::assertNotSentTo($this->user, EpicStatusChanged::class);
});

it('stays quiet when an update leaves the status alone', function () {
    Notification::fake();

    ($this->commentAs)($this->other);

    $this->epic->update(['title' => 'Renamed']);

    Notification::assertNothingSent();
});

// -------------------------------------------------------------------- the bell

function bellNotification(User $user, array $data, ?string $readAt = null)
{
    return $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => EpicCommented::class,
        'data' => $data,
        'read_at' => $readAt,
    ]);
}

it('shows the unread count and clears it on mark all read', function () {
    bellNotification($this->user, ['type' => 'epic_commented', 'epic_id' => $this->epic->id, 'epic_title' => $this->epic->title, 'actor' => 'Sam', 'excerpt' => 'Hello']);
    bellNotification($this->user, ['type' => 'epic_commented', 'epic_id' => $this->epic->id, 'epic_title' => $this->epic->title, 'actor' => 'Sam', 'excerpt' => 'Again'], now());

    $component = Livewire::test('notification-bell');

    expect($component->viewData('unreadCount'))->toBe(1)
        ->and($component->viewData('notifications'))->toHaveCount(2);

    $component->call('markAllRead');

    expect($component->viewData('unreadCount'))->toBe(0);
});

it('opens a notification onto its epic and marks it read', function () {
    $notification = bellNotification($this->user, ['type' => 'epic_commented', 'epic_id' => $this->epic->id, 'epic_title' => $this->epic->title, 'actor' => 'Sam']);

    Livewire::test('notification-bell')
        ->call('open', $notification->id)
        ->assertRedirect("/epics/{$this->epic->id}/edit");

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('falls back to the epic list when the epic is gone', function () {
    $notification = bellNotification($this->user, ['type' => 'epic_commented', 'epic_id' => 999999, 'epic_title' => 'Deleted', 'actor' => 'Sam']);

    Livewire::test('notification-bell')
        ->call('open', $notification->id)
        ->assertRedirect('/epics');
});

it('will not open a notification belonging to somebody else', function () {
    $notification = bellNotification($this->other, ['type' => 'epic_commented', 'epic_id' => $this->epic->id, 'epic_title' => $this->epic->title, 'actor' => 'Sam']);

    Livewire::test('notification-bell')
        ->call('open', $notification->id)
        ->assertStatus(404);

    expect($notification->fresh()->read_at)->toBeNull();
});
