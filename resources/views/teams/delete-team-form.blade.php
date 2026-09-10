<section class="space-y-6">
    <div>
        <flux:heading>{{ __('Delete team') }}</flux:heading>
        <flux:subheading>{{ __('Remove this team and everything in it') }}</flux:subheading>
    </div>

    <div class="space-y-4 rounded-lg border border-red-200 bg-red-50 p-4 text-red-700 dark:border-red-200/10 dark:bg-red-900/20 dark:text-red-100">
        <div>
            <p class="font-medium">{{ __('This cannot be undone.') }}</p>
            <p class="text-sm">{{ __('Its epics, squads, engineers and settings go with it. Export anything you want to keep first.') }}</p>
        </div>

        <flux:button variant="danger" wire:click="$toggle('confirmingTeamDeletion')" wire:loading.attr="disabled" data-test="delete-team-button">
            {{ __('Delete team') }}
        </flux:button>
    </div>

    <flux:modal wire:model.self="confirmingTeamDeletion" class="max-w-lg">
        <form wire:submit="deleteTeam" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete :name?', ['name' => $team->name]) }}</flux:heading>
                <flux:subheading>{{ __('Everything in this team will be permanently deleted.') }}</flux:subheading>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button variant="filled" wire:click="$toggle('confirmingTeamDeletion')" wire:loading.attr="disabled">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" type="submit" wire:loading.attr="disabled" data-test="delete-team-confirm">{{ __('Delete team') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
