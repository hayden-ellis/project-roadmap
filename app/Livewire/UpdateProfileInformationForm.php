<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;
use Laravel\Jetstream\Http\Livewire\UpdateProfileInformationForm as JetstreamForm;

/**
 * Jetstream's form, with one change: a picked photo saves immediately instead
 * of waiting for the Save button, matching how engineer photos behave.
 */
class UpdateProfileInformationForm extends JetstreamForm
{
    public function updatedPhoto(): void
    {
        $this->validate(['photo' => ['mimes:jpg,jpeg,png,webp', 'max:2048']], [
            'photo.mimes' => __('That file is not an image.'),
            'photo.max' => __('Keep it under 2 MB.'),
        ]);

        Auth::user()->updateProfilePhoto($this->photo);

        // A full navigation, like Jetstream's own post-photo redirect: the
        // sidebar avatar lives outside this component and only a fresh page
        // picks the new face up.
        $this->redirect(route('profile.show'), navigate: true);
    }
}
