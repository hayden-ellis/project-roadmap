<form wire:submit="updateProfileInformation" class="space-y-6">
    <x-saved-toast on="saved" text="Profile saved." />

    @if (Laravel\Jetstream\Jetstream::managesProfilePhotos())
        <flux:field>
            <flux:label>{{ __('Photo') }}</flux:label>

            <div class="mt-2 flex items-center gap-4">
                {{-- Initials until a photo exists; never a third-party
                     avatar service, same stance as the engineer faces. --}}
                <flux:avatar
                    circle
                    size="xl"
                    :name="$this->user->name"
                    :src="$this->user->profile_photo_path ? $this->user->profile_photo_url : null"
                />

                <div class="flex-1 min-w-0 space-y-2">
                    <flux:file-upload wire:model.live="photo" accept="image/png,image/jpeg,image/webp">
                        <flux:file-upload.dropzone
                            inline
                            heading="{{ __('Drop a photo here, or click to browse') }}"
                            text="{{ __('PNG, JPG or WebP, up to 2 MB. Saves right away.') }}"
                        />
                    </flux:file-upload>

                    @if ($this->user->profile_photo_path)
                        <flux:button type="button" size="sm" variant="subtle" wire:click="deleteProfilePhoto">
                            {{ __('Remove photo') }}
                        </flux:button>
                    @endif
                </div>
            </div>

            <flux:error name="photo" />
        </flux:field>
    @endif

    <flux:input wire:model="state.name" error:name="name" :label="__('Name')" type="text" required autocomplete="name" />

    <div>
        <flux:input wire:model="state.email" error:name="email" :label="__('Email')" type="email" required autocomplete="username" />

        @if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::emailVerification()) && ! $this->user->hasVerifiedEmail())
            <flux:text class="mt-3">
                {{ __('Your email address is unverified.') }}
                <flux:link class="cursor-pointer text-sm" wire:click.prevent="sendEmailVerification">
                    {{ __('Re-send the verification email.') }}
                </flux:link>
            </flux:text>

            @if ($this->verificationLinkSent)
                <flux:text class="mt-2 font-medium text-green-600 dark:text-green-400">
                    {{ __('A new verification link has been sent to your email address.') }}
                </flux:text>
            @endif
        @endif
    </div>

    <div>
        <flux:button variant="primary" type="submit" data-test="update-profile-button">
            {{ __('Save') }}
        </flux:button>
    </div>
</form>
