<section class="space-y-6">
    <x-saved-toast on="saved" text="Password updated." />

    <div>
        <flux:heading>{{ __('Password') }}</flux:heading>
        <flux:subheading>{{ __('Use a long, random password to stay secure') }}</flux:subheading>
    </div>

    <form wire:submit="updatePassword" class="space-y-6">
        <flux:input wire:model="state.current_password" error:name="current_password" :label="__('Current password')" type="password" required autocomplete="current-password" viewable />
        <flux:input wire:model="state.password" error:name="password" :label="__('New password')" type="password" required autocomplete="new-password" viewable />
        <flux:input wire:model="state.password_confirmation" error:name="password_confirmation" :label="__('Confirm password')" type="password" required autocomplete="new-password" viewable />

        <div>
            <flux:button variant="primary" type="submit" data-test="update-password-button">
                {{ __('Save') }}
            </flux:button>
        </div>
    </form>
</section>
