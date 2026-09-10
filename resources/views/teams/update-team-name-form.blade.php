<section class="space-y-6">
    <x-saved-toast on="saved" text="Team saved." />

    <form wire:submit="updateTeamName" class="space-y-6">
        <flux:field>
            <flux:label>{{ __('Owner') }}</flux:label>
            <div class="mt-2 flex items-center gap-3">
                <flux:avatar circle :name="$team->owner->name" :src="$team->owner->profile_photo_path ? $team->owner->profile_photo_url : null" />
                <div class="min-w-0 leading-tight">
                    <div class="truncate text-sm font-medium">{{ $team->owner->name }}</div>
                    <flux:text size="sm" class="truncate">{{ $team->owner->email }}</flux:text>
                </div>
            </div>
        </flux:field>

        <flux:input
            wire:model="state.name"
            error:name="name"
            :label="__('Team name')"
            type="text"
            required
            :disabled="! Gate::check('update', $team)"
            data-test="team-name-input"
        />

        @if (Gate::check('update', $team))
            <div>
                <flux:button variant="primary" type="submit" data-test="team-save-button">
                    {{ __('Save') }}
                </flux:button>
            </div>
        @endif
    </form>
</section>
