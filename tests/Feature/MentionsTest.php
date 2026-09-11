<?php

use App\Models\Epic;
use App\Models\EpicComment;
use App\Models\User;
use App\Notifications\EpicCommented;
use App\Notifications\EpicMentioned;
use App\Support\Mentions;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * An @mention reaches someone by name, whether or not they were already in
 * the conversation. The body stays plain text; the names are resolved when
 * the comment is saved.
 */
beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create(['name' => 'Hayden Ellis']);
    $this->team = $this->user->currentTeam;

    $this->join = function (string $name) {
        $member = User::factory()->create(['name' => $name]);
        $this->team->users()->attach($member, ['role' => 'editor']);

        return $member;
    };

    $this->priya = ($this->join)('Priya Sharma');
    $this->priyaLee = ($this->join)('Priya Sharma-Lee');
    $this->outsider = User::factory()->withPersonalTeam()->create(['name' => 'Sam Outsider']);

    $this->epic = Epic::create(['team_id' => $this->team->id, 'title' => 'Payments revamp']);

    $this->actingAs($this->user);
});

// ------------------------------------------------------------------ resolving

test('a member named after an @ resolves', function () {
    expect(Mentions::resolve($this->team, 'Ping @Priya Sharma about this')->pluck('id')->all())
        ->toBe([$this->priya->id]);
});

test('the owner is a member too, and matching ignores case', function () {
    expect(Mentions::resolve($this->team, '@hayden ellis?')->pluck('id')->all())
        ->toBe([$this->user->id]);
});

test('a name that begins with another whole name is not also the shorter one', function () {
    expect(Mentions::resolve($this->team, 'cc @Priya Sharma-Lee')->pluck('id')->all())
        ->toBe([$this->priyaLee->id]);
});

test('both can be named in one comment', function () {
    expect(Mentions::resolve($this->team, '@Priya Sharma and @Priya Sharma-Lee')->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->priya->id, $this->priyaLee->id])->sort()->values()->all());
});

test('an email address is not a mention', function () {
    expect(Mentions::resolve($this->team, 'mail hayden@Priya Sharma.test'))->toBeEmpty();
});

test('a name that is not a member is just text', function () {
    expect(Mentions::resolve($this->team, '@Sam Outsider @Nobody Here'))->toBeEmpty();
});

test('rendering escapes the body and wraps only real mentions', function () {
    $comment = EpicComment::create(['epic_id' => $this->epic->id, 'user_id' => $this->user->id, 'body' => '<b>@Priya Sharma</b> & @Nobody']);
    $comment->mentions()->sync([$this->priya->id]);

    $html = (string) Mentions::render($comment->fresh()->load('mentions'));

    expect($html)->toContain('&lt;b&gt;')
        ->toContain('<span class="'.Mentions::CHIP.'">@Priya Sharma</span>')
        ->toContain('&amp; @Nobody')
        ->not->toContain('<b>');
});

test('the picker offers members with logins, sorted by name', function () {
    expect(Mentions::choices($this->team)->pluck('name')->all())
        ->toBe(['Hayden Ellis', 'Priya Sharma', 'Priya Sharma-Lee']);
});

// ------------------------------------------------------------------ notifying

test('a mentioned member is told, even if they never touched the epic', function () {
    Notification::fake();

    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->set('commentBody', 'Thoughts, @Priya Sharma?')
        ->call('addComment');

    Notification::assertSentTo($this->priya, EpicMentioned::class);
    Notification::assertNotSentTo($this->priya, EpicCommented::class);
    Notification::assertNotSentTo($this->priyaLee, EpicMentioned::class);

    expect(EpicComment::first()->mentions->pluck('id')->all())->toBe([$this->priya->id]);
});

test('a mentioned participant hears once, as a mention', function () {
    Notification::fake();

    EpicComment::create(['epic_id' => $this->epic->id, 'user_id' => $this->priya->id, 'body' => 'Earlier.']);
    EpicComment::create(['epic_id' => $this->epic->id, 'user_id' => $this->priyaLee->id, 'body' => 'Also earlier.']);

    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->set('commentBody', '@Priya Sharma see above')
        ->call('addComment');

    Notification::assertSentTo($this->priya, EpicMentioned::class);
    Notification::assertNotSentTo($this->priya, EpicCommented::class);
    Notification::assertSentTo($this->priyaLee, EpicCommented::class);
    Notification::assertNotSentTo($this->priyaLee, EpicMentioned::class);
});

test('naming yourself tells nobody', function () {
    Notification::fake();

    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->set('commentBody', 'Note to self, @Hayden Ellis')
        ->call('addComment');

    Notification::assertNothingSent();
    expect(EpicComment::first()->mentions)->toBeEmpty();
});

test('the board flyout notifies the same way as the epic page', function () {
    Notification::fake();

    EpicComment::create(['epic_id' => $this->epic->id, 'user_id' => $this->priyaLee->id, 'body' => 'Earlier.']);

    Livewire::test('now')
        ->call('open', $this->epic->id)
        ->set('commentBody', '@Priya Sharma can you look?')
        ->call('addComment');

    Notification::assertSentTo($this->priya, EpicMentioned::class);
    Notification::assertSentTo($this->priyaLee, EpicCommented::class);
});

test('editing a comment tells only the newly named', function () {
    Notification::fake();

    $component = Livewire::test('epics.edit', ['epic' => $this->epic])
        ->set('commentBody', 'Hi @Priya Sharma')
        ->call('addComment');

    $comment = EpicComment::first();

    $component->call('editComment', $comment->id)
        ->set('editCommentBody', 'Hi @Priya Sharma and @Priya Sharma-Lee')
        ->call('updateComment');

    Notification::assertSentToTimes($this->priya, EpicMentioned::class, 1);
    Notification::assertSentToTimes($this->priyaLee, EpicMentioned::class, 1);
    expect($comment->fresh()->mentions->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->priya->id, $this->priyaLee->id])->sort()->values()->all());
});

test('editing a name out drops the mention without telling anyone', function () {
    Notification::fake();

    $comment = EpicComment::create(['epic_id' => $this->epic->id, 'user_id' => $this->user->id, 'body' => 'Hi @Priya Sharma']);
    $comment->mentions()->sync([$this->priya->id]);

    Livewire::test('epics.edit', ['epic' => $this->epic])
        ->call('editComment', $comment->id)
        ->set('editCommentBody', 'Hi everyone')
        ->call('updateComment');

    Notification::assertNothingSent();
    expect($comment->fresh()->mentions)->toBeEmpty();
});

test('the mention goes by database and mail and reads right in the bell', function () {
    $comment = EpicComment::create(['epic_id' => $this->epic->id, 'user_id' => $this->user->id, 'body' => 'Hi @Priya Sharma']);
    $notification = new EpicMentioned($comment);

    expect($notification->via($this->priya))->toBe(['database', 'mail'])
        ->and($notification->toArray($this->priya)['type'])->toBe('epic_mentioned')
        ->and($notification->toMail($this->priya)->subject)->toBe('Hayden Ellis mentioned you on "Payments revamp"');

    $this->priya->notify($notification);
    $this->priya->refresh();

    $this->actingAs($this->priya);
    $this->priya->switchTeam($this->team);

    Livewire::test('notification-bell')
        ->assertSee('mentioned you on')
        ->assertSee('Payments revamp');
});

test('a deleted member is forgotten by the comments that named them', function () {
    $comment = EpicComment::create(['epic_id' => $this->epic->id, 'user_id' => $this->user->id, 'body' => 'Hi @Priya Sharma']);
    $comment->mentions()->sync([$this->priya->id]);

    $this->priya->delete();

    expect($comment->fresh()->mentions)->toBeEmpty();
});

test('rendering styles the author naming themselves, though nobody is told', function () {
    // Saved through the action, so the mentions relation leaves the author out.
    $comment = app(App\Actions\Comments\PostComment::class)->handle($this->epic, $this->user, 'note to self: @'.$this->user->name);

    expect($comment->fresh()->mentions)->toBeEmpty()
        ->and((string) Mentions::render($comment->fresh()->load('mentions')))
        ->toContain('<span class="'.Mentions::CHIP.'">@'.$this->user->name.'</span>');
});

test('rendering both names wraps each once', function () {
    $comment = EpicComment::create(['epic_id' => $this->epic->id, 'user_id' => $this->user->id, 'body' => '@Priya Sharma-Lee and @Priya Sharma']);
    $comment->mentions()->sync([$this->priya->id, $this->priyaLee->id]);

    $html = (string) Mentions::render($comment->fresh()->load('mentions'));

    expect(substr_count($html, '<span'))->toBe(2)
        ->and($html)->toContain('>@Priya Sharma-Lee</span> and <span');
});
