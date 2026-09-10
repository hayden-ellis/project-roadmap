<section class="mt-12 space-y-6">
    <div>
        <flux:heading>{{ __('Delete account') }}</flux:heading>
        <flux:subheading>{{ __('Remove your account and everything in it') }}</flux:subheading>
    </div>

    <flux:button variant="danger" wire:click="confirmUserDeletion" wire:loading.attr="disabled" data-test="delete-user-button">
        {{ __('Delete account') }}
    </flux:button>

    <flux:modal wire:model.self="confirmingUserDeletion" class="max-w-lg">
        <form wire:submit="deleteUser" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete your account?') }}</flux:heading>
                <flux:subheading>
                    {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Enter your password to confirm.') }}
                </flux:subheading>
            </div>

            <flux:input wire:model="password" :label="__('Password')" type="password" viewable autocomplete="current-password" autofocus />

            <div class="flex justify-end gap-2">
                <flux:button variant="filled" wire:click="$toggle('confirmingUserDeletion')" wire:loading.attr="disabled">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" type="submit" wire:loading.attr="disabled" data-test="confirm-delete-user-button">{{ __('Delete account') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
