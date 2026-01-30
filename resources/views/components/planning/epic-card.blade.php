@props([
    'epic',
    'squadId',
    'inPlan' => false,
    'storyPoints' => null,
    'statuses',
    'categories',
    'showPopover' => true,
])

<div
    wire:key="epic-card-{{ $epic->id }}-{{ $squadId }}"
    wire:sort:item="{{ $epic->id }}"
    class="group p-3 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-lg shadow-sm hover:shadow-md transition-all cursor-grab active:cursor-grabbing"
>
    <div class="flex items-start justify-between gap-2">
        <div class="flex-1 min-w-0">
            @if($showPopover)
            <flux:dropdown>
                <flux:button variant="ghost" size="sm" class="-ml-2 font-medium text-left truncate max-w-full">
                    <span class="truncate">{{ $epic->title }}</span>
                    <flux:icon.pencil-square variant="micro" class="ml-1 opacity-0 group-hover:opacity-50 shrink-0" />
                </flux:button>
                <x-planning.epic-popover
                    :epic="$epic"
                    :squadId="$squadId"
                    :storyPoints="$storyPoints"
                    :statuses="$statuses"
                    :categories="$categories"
                />
            </flux:dropdown>
            @else
            <flux:text class="font-medium truncate">{{ $epic->title }}</flux:text>
            @endif

            <div class="flex flex-wrap items-center gap-1.5 mt-1.5">
                <flux:badge
                    :color="$epic->status->slug === 'completed' ? 'green' : ($epic->status->slug === 'in-progress' ? 'blue' : ($epic->status->slug === 'blocked' ? 'red' : 'zinc'))"
                    size="sm"
                >
                    {{ $epic->status->name }}
                </flux:badge>

                @if($epic->category)
                <span
                    class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs"
                    style="background-color: {{ $epic->category->color }}15; color: {{ $epic->category->color }}"
                >
                    <span class="h-1.5 w-1.5 rounded-full" style="background-color: {{ $epic->category->color }}"></span>
                    {{ $epic->category->name }}
                </span>
                @endif
            </div>

            @php
                $otherSquads = $epic->squads->where('id', '!=', $squadId);
            @endphp
            @if($otherSquads->isNotEmpty())
            <div class="flex items-center gap-1 mt-2">
                <flux:text class="text-[10px] text-zinc-400">Also:</flux:text>
                @foreach($otherSquads->take(2) as $squad)
                <span class="inline-flex items-center gap-0.5 px-1 py-0.5 rounded text-[10px] bg-zinc-100 dark:bg-zinc-800">
                    <span class="h-1 w-1 rounded-full" style="background-color: {{ $squad->color }}"></span>
                    {{ $squad->name }}
                </span>
                @endforeach
                @if($otherSquads->count() > 2)
                <span class="text-[10px] text-zinc-400">+{{ $otherSquads->count() - 2 }}</span>
                @endif
            </div>
            @endif
        </div>

        <div class="flex flex-col items-end gap-1 shrink-0">
            @if($inPlan && $storyPoints)
            <flux:badge color="blue" size="sm">{{ $storyPoints }} pts</flux:badge>
            @endif
        </div>
    </div>
</div>
