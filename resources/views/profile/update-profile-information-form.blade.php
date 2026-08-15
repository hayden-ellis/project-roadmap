<x-form-section submit="updateProfileInformation">
    <x-slot name="title">
        {{ __('Profile Information') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Update your account\'s profile information and email address.') }}
    </x-slot>

    <x-slot name="form">
        <!-- Profile Photo -->
        @if (Laravel\Jetstream\Jetstream::managesProfilePhotos())
            <div class="col-span-6 sm:col-span-4">
                <x-label value="{{ __('Photo') }}" />

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

                <x-input-error for="photo" class="mt-2" />
            </div>
        @endif

        <!-- Name -->
        <div class="col-span-6 sm:col-span-4">
            <x-label for="name" value="{{ __('Name') }}" />
            <x-input id="name" type="text" class="mt-1 block w-full" wire:model="state.name" required autocomplete="name" />
            <x-input-error for="name" class="mt-2" />
        </div>

        <!-- Email -->
        <div class="col-span-6 sm:col-span-4">
            <x-label for="email" value="{{ __('Email') }}" />
            <x-input id="email" type="email" class="mt-1 block w-full" wire:model="state.email" required autocomplete="username" />
            <x-input-error for="email" class="mt-2" />

            @if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::emailVerification()) && ! $this->user->hasVerifiedEmail())
                <p class="text-sm mt-2 text-zinc-600 dark:text-zinc-400">
                    {{ __('Your email address is unverified.') }}

                    <button type="button" class="underline text-sm text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-100 rounded-md focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" wire:click.prevent="sendEmailVerification">
                        {{ __('Click here to re-send the verification email.') }}
                    </button>
                </p>

                @if ($this->verificationLinkSent)
                    <p class="mt-2 font-medium text-sm text-green-600 dark:text-green-400">
                        {{ __('A new verification link has been sent to your email address.') }}
                    </p>
                @endif
            @endif
        </div>
    </x-slot>

    <x-slot name="actions">
        <x-action-message class="me-3" on="saved">
            {{ __('Saved.') }}
        </x-action-message>

        <x-button wire:loading.attr="disabled" wire:target="photo">
            {{ __('Save') }}
        </x-button>
    </x-slot>
</x-form-section>
