@props(['epic', 'squadId', 'storyPoints', 'statuses', 'categories'])

<flux:popover class="w-96">
    <div class="flex flex-col gap-3" x-data="{
        title: {{ json_encode($epic->title) }},
        storyPoints: {{ $storyPoints ?? 'null' }},
        statusId: '{{ $epic->status_id }}',
        categoryId: '{{ $epic->category_id ?? '' }}',
        description: {{ json_encode($epic->description ?? '') }},
        saving: false,
        saved: false,
        saveData() {
            this.saving = true;
            this.saved = false;
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
                this.saved = true;
                setTimeout(() => { this.saved = false; }, 2000);
            });
        }
    }">
        {{-- Title --}}
        <div>
            <flux:input x-model="title" @change="saveData()" size="sm" class="font-semibold" placeholder="Epic title" />
            @if($epic->start_date && $epic->end_date)
            <flux:text class="text-xs text-zinc-500 mt-1">
                {{ $epic->start_date->format('M j') }} - {{ $epic->end_date->format('M j, Y') }}
            </flux:text>
            @endif
        </div>

        <flux:separator variant="subtle" />

        {{-- Status & Category --}}
        <div class="grid grid-cols-2 gap-3">
            <div>
                <flux:text class="text-xs text-zinc-500 font-medium mb-1">Status</flux:text>
                <select x-model="statusId" @change="saveData()" class="w-full text-sm rounded-md border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 focus:border-blue-500 focus:ring-blue-500">
                    @foreach($statuses as $status)
                        <option value="{{ $status->id }}">{{ $status->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <flux:text class="text-xs text-zinc-500 font-medium mb-1">Category</flux:text>
                <select x-model="categoryId" @change="saveData()" class="w-full text-sm rounded-md border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 focus:border-blue-500 focus:ring-blue-500">
                    <option value="">None</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Story Points --}}
        <div>
            <flux:text class="text-xs text-zinc-500 font-medium mb-1">Story Points (this squad)</flux:text>
            <flux:input type="number" x-model="storyPoints" @change="saveData()" size="sm" placeholder="e.g., 21" min="0" />
        </div>

        {{-- Description --}}
        <div>
            <flux:text class="text-xs text-zinc-500 font-medium mb-1">Description</flux:text>
            <textarea
                x-model="description"
                @change="saveData()"
                rows="3"
                placeholder="Add notes..."
                class="w-full text-sm rounded-md border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 focus:border-blue-500 focus:ring-blue-500"
            ></textarea>
        </div>

        {{-- Other squads indicator --}}
        @if($epic->squads->count() > 1)
        <div class="flex flex-wrap gap-1 items-center">
            <flux:text class="text-[10px] text-zinc-500 font-medium uppercase tracking-wide">All Squads:</flux:text>
            @foreach($epic->squads as $squad)
            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px]" style="background-color: {{ $squad->color }}20; color: {{ $squad->color }}">
                <div class="h-1 w-1 rounded-full" style="background-color: {{ $squad->color }}"></div>
                {{ $squad->name }}
                @if($squad->pivot->story_points)
                    ({{ $squad->pivot->story_points }})
                @endif
            </span>
            @endforeach
        </div>
        @endif

        <flux:separator variant="subtle" />

        {{-- Save indicator --}}
        <div class="flex items-center justify-center gap-2">
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

        {{-- View full epic link --}}
        <div class="text-center">
            <a href="/epics/{{ $epic->id }}/edit" wire:navigate class="inline-flex items-center gap-1.5 text-sm text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition-colors">
                <span>Edit Full Epic</span>
                <flux:icon.arrow-top-right-on-square class="size-4" />
            </a>
        </div>
    </div>
</flux:popover>
