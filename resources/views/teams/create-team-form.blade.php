<form wire:submit="createTeam" class="space-y-6">
    <flux:field>
        <flux:label>{{ __('Owner') }}</flux:label>
        <div class="mt-2 flex items-center gap-3">
            <flux:avatar circle :name="$this->user->name" :src="$this->user->profile_photo_path ? $this->user->profile_photo_url : null" />
            <div class="min-w-0 leading-tight">
                <div class="truncate text-sm font-medium">{{ $this->user->name }}</div>
                <flux:text size="sm" class="truncate">{{ $this->user->email }}</flux:text>
            </div>
        </div>
    </flux:field>

    <flux:input wire:model="state.name" error:name="name" :label="__('Team name')" type="text" required autofocus data-test="team-name-input" />

    <div>
        <flux:button variant="primary" type="submit" data-test="create-team-button">
            {{ __('Create team') }}
        </flux:button>
    </div>
</form>
