<?php

use App\Livewire\UpdateProfileInformationForm;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('current profile information is available', function () {
    $this->actingAs($user = User::factory()->create());

    $component = Livewire::test(UpdateProfileInformationForm::class);

    expect($component->state['name'])->toEqual($user->name);
    expect($component->state['email'])->toEqual($user->email);
});

test('profile information can be updated', function () {
    $this->actingAs($user = User::factory()->create());

    Livewire::test(UpdateProfileInformationForm::class)
        ->set('state', ['name' => 'Test Name', 'email' => 'test@example.com'])
        ->call('updateProfileInformation');

    expect($user->fresh())
        ->name->toEqual('Test Name')
        ->email->toEqual('test@example.com');
});

test('a picked profile photo saves immediately, without the save button', function () {
    Storage::fake('public');

    $this->actingAs($user = User::factory()->create());

    Livewire::test(UpdateProfileInformationForm::class)
        ->set('photo', UploadedFile::fake()->image('me.jpg'))
        ->assertHasNoErrors()
        ->assertRedirect(route('profile.show'));

    $path = $user->fresh()->profile_photo_path;

    expect($path)->not->toBeNull()
        ->and(Storage::disk('public')->exists($path))->toBeTrue();
});

test('a profile photo can be removed', function () {
    Storage::fake('public');

    $this->actingAs($user = User::factory()->create());

    Livewire::test(UpdateProfileInformationForm::class)
        ->set('photo', UploadedFile::fake()->image('me.jpg'));

    $path = $user->fresh()->profile_photo_path;

    Livewire::test(UpdateProfileInformationForm::class)
        ->call('deleteProfilePhoto');

    expect($user->fresh()->profile_photo_path)->toBeNull()
        ->and(Storage::disk('public')->exists($path))->toBeFalse();
});

test('a non-image file is rejected as a profile photo', function () {
    Storage::fake('public');

    $this->actingAs($user = User::factory()->create());

    Livewire::test(UpdateProfileInformationForm::class)
        ->set('photo', UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'))
        ->assertHasErrors('photo');

    expect($user->fresh()->profile_photo_path)->toBeNull();
});
