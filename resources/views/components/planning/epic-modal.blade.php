@props(['epic', 'squadId', 'storyPoints', 'statuses', 'categories', 'quarter', 'squadName'])

<flux:modal name="edit-epic-{{ $epic->id }}" class="w-full max-w-lg">
    <div class="space-y-6" x-data="{
        title: {{ json_encode($epic->title) }},
        storyPoints: {{ $storyPoints ?? 'null' }},
        statusId: '{{ $epic->status_id }}',
        categoryId: '{{ $epic->plan_category_id ?? '' }}',
        description: {{ json_encode($epic->description ?? '') }},
        saving: false,
        save() {
            this.saving = true;
            $wire.updateEpicFromPopover(
                {{ $epic->id }},
                {{ $squadId }},
                this.title,
                this.storyPoints ? parseInt(this.storyPoints) : null,
                this.statusId,
                this.categoryId || null,
                this.description
            ).then(() => {
                this.saving = false;
                $dispatch('modal-close', { name: 'edit-epic-{{ $epic->id }}' });
            });
        }
    }">
        {{-- Header --}}
        <div class="flex items-center justify-between gap-4 pr-8">
            <flux:heading size="lg">Edit Epic</flux:heading>
            <div class="flex items-center gap-2">
                <flux:badge color="blue" size="sm">{{ $quarter }}</flux:badge>
                <flux:badge color="zinc" size="sm">{{ $squadName }}</flux:badge>
                @if($epic->start_date && $epic->end_date)
                <span class="text-xs text-zinc-500">{{ $epic->start_date->format('M j') }} - {{ $epic->end_date->format('M j, Y') }}</span>
                @endif
            </div>
        </div>

        {{-- Title --}}
        <flux:field>
            <flux:label>Title</flux:label>
            <flux:input x-model="title" placeholder="Epic title" />
        </flux:field>

        {{-- Status & Category --}}
        <div class="grid grid-cols-2 gap-4">
            <flux:field>
                <flux:label>Status</flux:label>
                <flux:select x-model="statusId">
                    @foreach($statuses as $status)
                        <flux:select.option value="{{ $status->id }}">{{ $status->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>
            <flux:field>
                <flux:label>Category (this plan)</flux:label>
                <flux:select x-model="categoryId">
                    <flux:select.option value="">Uncategorized</flux:select.option>
                    @foreach($categories as $category)
                        <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>
        </div>

        {{-- Story Points --}}
        <flux:field>
            <flux:label>Story Points for {{ $quarter }}</flux:label>
            <flux:description>Points allocated for {{ $squadName }} this quarter only</flux:description>
            <flux:input type="number" x-model="storyPoints" placeholder="e.g., 21" min="0" />
        </flux:field>

        {{-- Description --}}
        <flux:field>
            <flux:label>Description</flux:label>
            <flux:textarea x-model="description" rows="4" placeholder="Add notes..." />
        </flux:field>

        {{-- Other squads indicator --}}
        @php
            // Deduplicate squads and sum story points across all quarters
            $uniqueSquads = $epic->squads->groupBy('id')->map(function ($squadRecords) {
                $squad = $squadRecords->first();
                $totalPoints = $squadRecords->sum('pivot.story_points');
                return (object) [
                    'id' => $squad->id,
                    'name' => $squad->name,
                    'color' => $squad->color,
                    'total_story_points' => $totalPoints,
                ];
            });
        @endphp
        @if($uniqueSquads->count() > 1)
        <div class="flex flex-wrap gap-2 items-center">
            <flux:text class="text-xs text-zinc-500 font-medium uppercase tracking-wide">All Squads:</flux:text>
            @foreach($uniqueSquads as $squad)
            <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded text-xs" style="background-color: {{ $squad->color }}20; color: {{ $squad->color }}">
                <div class="h-1.5 w-1.5 rounded-full" style="background-color: {{ $squad->color }}"></div>
                {{ $squad->name }}
                @if($squad->total_story_points)
                    ({{ $squad->total_story_points }} pts total)
                @endif
            </span>
            @endforeach
        </div>
        @endif

        {{-- Footer --}}
        <div class="flex items-center justify-between pt-4 border-t border-zinc-200 dark:border-zinc-700">
            <a href="/epics/{{ $epic->id }}/edit" wire:navigate class="inline-flex items-center gap-1.5 text-sm text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition-colors">
                <span>Edit Full Epic</span>
                <flux:icon.arrow-top-right-on-square class="size-4" />
            </a>

            <div class="flex items-center gap-3">
                <flux:button variant="ghost" x-on:click="$dispatch('modal-close', { name: 'edit-epic-{{ $epic->id }}' })">Cancel</flux:button>
                <flux:button variant="primary" x-on:click="save()" x-bind:disabled="saving">
                    <span x-show="!saving">Save</span>
                    <span x-show="saving" x-cloak class="flex items-center gap-1.5">
                        <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Saving...
                    </span>
                </flux:button>
            </div>
        </div>
    </div>
</flux:modal>
