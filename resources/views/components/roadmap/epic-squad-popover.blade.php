@props(['epic', 'work', 'squadStart', 'squadEnd'])

<flux:popover class="w-96">
    <div class="flex flex-col gap-3" x-data="{
        startDate: '{{ $work['start_date']?->format('Y-m-d') ?? '' }}',
        endDate: '{{ $work['end_date']?->format('Y-m-d') ?? '' }}',
        storyPoints: {{ $work['story_points'] ?? 'null' }},
        saving: false,
        saved: false,
        saveData() {
            this.saving = true;
            this.saved = false;
            $wire.updateSquadWork(
                {{ $epic->id }},
                {{ $work['squad']->id }},
                this.startDate || null,
                this.endDate || null,
                this.storyPoints ? parseInt(this.storyPoints) : null
            ).then(() => {
                this.saving = false;
                this.saved = true;
                setTimeout(() => { this.saved = false; }, 2000);
            });
        }
    }">
        <div>
            <div class="flex items-center justify-between gap-2">
                <flux:heading size="lg" class="flex-1 truncate">{{ $epic->title }}</flux:heading>
                <flux:badge :color="$epic->status->slug === 'completed' ? 'green' : ($epic->status->slug === 'in-progress' ? 'blue' : ($epic->status->slug === 'blocked' ? 'red' : 'zinc'))" size="sm">
                    {{ $epic->status->name }}
                </flux:badge>
            </div>
            @if($epic->description)
            <flux:text class="text-xs text-zinc-600 dark:text-zinc-400 line-clamp-3 mt-1">{{ $epic->description }}</flux:text>
            @endif
        </div>

        <flux:separator variant="subtle" />
        
        <div class="space-y-3">
            <div class="flex items-center gap-2">
                <div class="h-2.5 w-2.5 rounded-full flex-shrink-0" style="background-color: {{ $work['squad']->color }}"></div>
                <flux:text class="text-sm font-medium">{{ $work['squad']->name }}</flux:text>
            </div>

            <div class="flex gap-2">
                <div class="flex-1">
                    <flux:text class="text-xs text-zinc-500 dark:text-zinc-400 font-medium">Start Date</flux:text>
                    <flux:input type="date" x-model="startDate" @change="saveData()" class="mt-1" size="sm" />
                </div>
                <div class="flex-1">
                    <flux:text class="text-xs text-zinc-500 dark:text-zinc-400 font-medium">End Date</flux:text>
                    <flux:input type="date" x-model="endDate" @change="saveData()" class="mt-1" size="sm" />
                </div>
            </div>

            <div>
                <flux:text class="text-xs text-zinc-500 dark:text-zinc-400 font-medium">Story Points</flux:text>
                <flux:input type="number" x-model="storyPoints" @change="saveData()" class="mt-1" size="sm" placeholder="e.g., 21" min="0" />
            </div>

            @if($epic->squads->count() > 1)
            <div class="flex flex-wrap gap-1 items-center">
                <flux:text class="text-[10px] text-zinc-500 dark:text-zinc-400 font-medium uppercase tracking-wide">All Squads:</flux:text>
                @foreach($epic->squads as $squad)
                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px]" style="background-color: {{ $squad->color }}20; color: {{ $squad->color }}">
                    <div class="h-1 w-1 rounded-full" style="background-color: {{ $squad->color }}"></div>
                    {{ $squad->name }}
                </span>
                @endforeach
            </div>
            @endif
        </div>

        <flux:separator variant="subtle" />

        <div class="flex items-center justify-center gap-2 mb-1.5">
            <span x-show="saving" x-cloak class="text-xs text-blue-600 dark:text-blue-400 flex items-center gap-1">
                <svg class="animate-spin h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Saving...
            </span>
            <span x-show="saved" x-cloak class="text-xs text-green-600 dark:text-green-400 flex items-center gap-1">
                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                Saved
            </span>
        </div>

        <div class="text-center">
            <a href="/epics/{{ $epic->id }}/edit" target="_blank" class="inline-flex items-center gap-1.5 text-sm text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition-colors">
                <span>View Full Epic</span>
                <flux:icon.arrow-top-right-on-square class="size-4" />
            </a>
        </div>
    </div>
</flux:popover>

