<?php

use App\Models\Epic;
use App\Models\EpicComment;
use App\Models\Status;
use App\Models\User;
use Livewire\Livewire;

/**
 * Comments are the one object with a personal author, so alongside the usual
 * tenancy fences these tests pin the author-only edit/delete rule and the
 * single-level threading contract (a reply to a reply joins the same thread).
 */
beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;

    $this->backlog = Status::create([
        'team_id' => $this->team->id,
        'name' => 'Backlog',
        'color' => '#71717A',
        'is_default' => true,
    ]);

    $this->makeEpic = fn (string $title = 'Checkout Redesign') => Epic::create([
        'team_id' => $this->team->id,
        'title' => $title,
        'status_id' => $this->backlog->id,
    ]);

    // A second account on the same team -- the author-only rules need someone
    // who is allowed in the room but did not write the comment.
    $this->teammate = User::factory()->create();
    $this->team->users()->attach($this->teammate, ['role' => 'admin']);
    $this->teammate->switchTeam($this->team);

    $this->foreignEpic = function () {
        $otherUser = User::factory()->withPersonalTeam()->create();

        return Epic::create([
            'team_id' => $otherUser->currentTeam->id,
            'title' => 'Their work',
        ]);
    };

    $this->commentOn = fn (Epic $epic, ?User $author = null, string $body = 'Vendor confirmed the fix ships Friday.') => EpicComment::create([
        'epic_id' => $epic->id,
        'user_id' => ($author ?? $this->user)->id,
        'body' => $body,
    ]);

    $this->actingAs($this->user);
});

it('shows an epic\'s comments in the flyout', function () {
    $epic = ($this->makeEpic)();
    ($this->commentOn)($epic, body: 'Vendor confirmed the fix ships Friday.');

    // The flyout opens on Details; the thread lives one tab over.
    Livewire::test('now')
        ->call('open', $epic->id)
        ->assertSet('flyoutTab', 'details')
        ->call('setFlyoutTab', 'comments')
        ->assertSee('Vendor confirmed the fix ships Friday.')
        ->assertViewHas('openComments', fn ($comments) => $comments->count() === 1);
});

it('adds a comment to the open epic', function () {
    $epic = ($this->makeEpic)();

    Livewire::test('now')
        ->call('open', $epic->id)
        ->set('commentBody', 'First!')
        ->call('addComment')
        ->assertSet('commentBody', '');

    expect($epic->comments()->sole())
        ->body->toBe('First!')
        ->user_id->toBe($this->user->id)
        ->parent_id->toBeNull();
});

it('rejects an empty comment', function () {
    $epic = ($this->makeEpic)();

    Livewire::test('now')
        ->call('open', $epic->id)
        ->call('addComment')
        ->assertHasErrors(['commentBody' => 'required']);

    expect(EpicComment::count())->toBe(0);
});

it('attaches a reply to the comment being answered', function () {
    $epic = ($this->makeEpic)();
    $root = ($this->commentOn)($epic);

    Livewire::test('now')
        ->call('open', $epic->id)
        ->call('replyTo', $root->id)
        ->set('commentBody', 'On it.')
        ->call('addComment');

    expect($epic->comments()->whereNotNull('parent_id')->sole()->parent_id)->toBe($root->id);
});

it('re-roots a reply to a reply onto the root comment', function () {
    $epic = ($this->makeEpic)();
    $root = ($this->commentOn)($epic);
    $reply = EpicComment::factory()->replyTo($root)->create(['user_id' => $this->teammate->id]);

    Livewire::test('now')
        ->call('open', $epic->id)
        ->call('replyTo', $reply->id)
        ->set('commentBody', 'Joining the thread.')
        ->call('addComment');

    expect(EpicComment::where('body', 'Joining the thread.')->sole()->parent_id)->toBe($root->id);
});

it('lets the author edit their comment', function () {
    $epic = ($this->makeEpic)();
    $comment = ($this->commentOn)($epic);

    Livewire::test('now')
        ->call('open', $epic->id)
        ->call('editComment', $comment->id)
        ->assertSet('editCommentBody', $comment->body)
        ->set('editCommentBody', 'Actually, Monday.')
        ->call('updateComment')
        ->assertSet('editingCommentId', null);

    expect($comment->fresh()->body)->toBe('Actually, Monday.');
});

it('lets the author delete their comment', function () {
    $epic = ($this->makeEpic)();
    $comment = ($this->commentOn)($epic);

    Livewire::test('now')
        ->call('open', $epic->id)
        ->call('deleteComment', $comment->id);

    expect(EpicComment::find($comment->id))->toBeNull();
});

it('stops a teammate editing or deleting someone else\'s comment', function () {
    $epic = ($this->makeEpic)();
    $comment = ($this->commentOn)($epic, $this->teammate);

    Livewire::test('now')
        ->call('open', $epic->id)
        ->call('editComment', $comment->id)
        ->assertForbidden();

    Livewire::test('now')
        ->call('open', $epic->id)
        ->call('deleteComment', $comment->id)
        ->assertForbidden();

    expect(EpicComment::find($comment->id))->not->toBeNull();
});

it('sweeps replies when the root comment is deleted', function () {
    $epic = ($this->makeEpic)();
    $root = ($this->commentOn)($epic);
    $reply = EpicComment::factory()->replyTo($root)->create(['user_id' => $this->teammate->id]);

    Livewire::test('now')
        ->call('open', $epic->id)
        ->call('deleteComment', $root->id);

    expect(EpicComment::find($reply->id))->toBeNull();
});

it('refuses to comment on another team\'s epic', function () {
    $foreign = ($this->foreignEpic)();

    Livewire::test('now')
        ->set('openEpicId', $foreign->id)
        ->set('commentBody', 'Sneaky.')
        ->call('addComment')
        ->assertForbidden();

    expect(EpicComment::count())->toBe(0);
});

it('refuses to reply to a comment from another team\'s epic', function () {
    $epic = ($this->makeEpic)();
    $foreignComment = EpicComment::create([
        'epic_id' => ($this->foreignEpic)()->id,
        'user_id' => $this->user->id,
        'body' => 'Elsewhere.',
    ]);

    Livewire::test('now')
        ->call('open', $epic->id)
        ->set('replyingToId', $foreignComment->id)
        ->set('commentBody', 'Crossing the fence.')
        ->call('addComment')
        ->assertForbidden();

    expect(EpicComment::count())->toBe(1);
});

it('shows the thread and takes a comment on the epic page', function () {
    $epic = ($this->makeEpic)();
    $root = ($this->commentOn)($epic, body: 'Certification news?');

    Livewire::test('epics.edit', ['epic' => $epic])
        ->assertSee('Certification news?')
        ->call('replyTo', $root->id)
        ->set('commentBody', 'Vendor call booked for Thursday.')
        ->call('addComment');

    expect($epic->comments()->whereNotNull('parent_id')->sole())
        ->parent_id->toBe($root->id)
        ->body->toBe('Vendor call booked for Thursday.');
});
