<?php

use App\Models\Engineer;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Faces are stored locally on the public disk. The engineer's own photo wins;
 * a linked account's Jetstream photo is only the fallback.
 */
beforeEach(function () {
    Storage::fake('public');

    $user = User::factory()->withPersonalTeam()->create();
    $this->team = $user->currentTeam;

    $this->engineer = Engineer::create([
        'team_id' => $this->team->id,
        'name' => 'Ben Norrichs',
        'default_weekly_points' => 10,
        'is_active' => true,
    ]);

    $this->actingAs($user);
});

it('stores an uploaded photo locally and shows it as the avatar', function () {
    Livewire::test('engineers.edit', ['engineer' => $this->engineer])
        ->set('avatarUpload', UploadedFile::fake()->image('ben.jpg'))
        ->assertHasNoErrors();

    $path = $this->engineer->fresh()->avatar_path;

    expect($path)->not->toBeNull()
        ->and(Storage::disk('public')->exists($path))->toBeTrue()
        ->and($this->engineer->fresh()->avatarUrl())->toContain($path);
});

it('replaces the old file when a new photo is uploaded', function () {
    $component = Livewire::test('engineers.edit', ['engineer' => $this->engineer])
        ->set('avatarUpload', UploadedFile::fake()->image('first.jpg'));

    $first = $this->engineer->fresh()->avatar_path;

    $component->set('avatarUpload', UploadedFile::fake()->image('second.jpg'));

    $second = $this->engineer->fresh()->avatar_path;

    expect($second)->not->toBe($first)
        ->and(Storage::disk('public')->exists($second))->toBeTrue()
        ->and(Storage::disk('public')->exists($first))->toBeFalse();
});

it('removes the photo and its file', function () {
    Livewire::test('engineers.edit', ['engineer' => $this->engineer])
        ->set('avatarUpload', UploadedFile::fake()->image('ben.jpg'));

    $path = $this->engineer->fresh()->avatar_path;

    Livewire::test('engineers.edit', ['engineer' => $this->engineer->fresh()])
        ->call('removeAvatar');

    expect($this->engineer->fresh()->avatar_path)->toBeNull()
        ->and(Storage::disk('public')->exists($path))->toBeFalse();
});

it('rejects a file that is not an image', function () {
    Livewire::test('engineers.edit', ['engineer' => $this->engineer])
        ->set('avatarUpload', UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'))
        ->assertHasErrors('avatarUpload');

    expect($this->engineer->fresh()->avatar_path)->toBeNull();
});

it('cleans up the file when the engineer is deleted', function () {
    Livewire::test('engineers.edit', ['engineer' => $this->engineer])
        ->set('avatarUpload', UploadedFile::fake()->image('ben.jpg'));

    $path = $this->engineer->fresh()->avatar_path;

    Livewire::test('engineers.edit', ['engineer' => $this->engineer->fresh()])
        ->call('delete');

    expect(Engineer::find($this->engineer->id))->toBeNull()
        ->and(Storage::disk('public')->exists($path))->toBeFalse();
});

it('prefers the engineer photo over a linked account photo', function () {
    $this->engineer->update([
        'user_id' => User::factory()->create(['profile_photo_path' => 'profile-photos/user.jpg'])->id,
        'avatar_path' => 'engineer-avatars/own.jpg',
    ]);

    expect($this->engineer->fresh()->avatarUrl())->toContain('engineer-avatars/own.jpg');
});
