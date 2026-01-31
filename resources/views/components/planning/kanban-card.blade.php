@props([
    'epic',
    'squadId',
    'planned' => false,
    'statuses' => null,
    'categories' => null,
    'quarter' => null,
    'squadName' => null,
])

@php
    $borderColor = $planned ? 'border-green-200 dark:border-green-900' : 'border-zinc-200 dark:border-zinc-700';
    $storyPoints = $planned ? ($epic->planned_story_points ?? 0) : ($epic->existing_story_points ?? null);
@endphp

<div
    x-sort:item="{{ $epic->id }}"
    wire:key="{{ $planned ? 'planned' : 'backlog' }}-{{ $epic->id }}"
    wire:transition
>
    <div
        @if($planned && $statuses && $categories)
        x-on:click="$dispatch('modal-show', { name: 'edit-epic-{{ $epic->id }}' })"
        @endif
        {{ $attributes->class([
            'group relative p-3 bg-white dark:bg-zinc-900 rounded-lg shadow-sm hover:shadow-md cursor-grab active:cursor-grabbing',
            'border',
            $borderColor,
        ]) }}
    >
        {{-- Actions (absolute positioned) --}}
        @if($planned)
        <button
            type="button"
            wire:click.stop="removeEpicFromPlan({{ $epic->id }}, {{ $squadId }})"
            class="absolute top-1.5 right-1.5 opacity-0 group-hover:opacity-100 z-10 p-1 rounded hover:bg-zinc-100 dark:hover:bg-zinc-800 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300"
        >
            <flux:icon.x-mark variant="micro" />
        </button>
        @else
        <div class="absolute top-1 right-1 opacity-0 group-hover:opacity-100 z-10" x-on:click.stop>
            <flux:button
                variant="primary"
                size="xs"
                icon="plus"
                wire:click="addEpicToPlan({{ $epic->id }}, [{{ $squadId }}])"
            >
                Add
            </flux:button>
        </div>
        @endif

        {{-- Title --}}
        <p class="font-medium text-sm truncate pr-8">{{ $epic->title }}</p>

        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400 line-clamp-1 h-4">{{ $epic->description ?: '' }}</p>

        <div class="flex items-center justify-between gap-1.5 mt-2">
            <div class="flex flex-wrap items-center gap-1.5">
                <x-planning.status-badge :status="$epic->status" />

                {{-- Show category badge only for backlog items (planned items are already in category columns) --}}
                @if(!$planned && $epic->category)
                <x-planning.category-badge :category="$epic->category" />
                @endif
            </div>

            @if($storyPoints)
            <flux:badge color="blue" size="sm">{{ $storyPoints }} pts</flux:badge>
            @endif
        </div>
    </div>

    @if($planned && $statuses && $categories)
    <x-planning.epic-modal
        :epic="$epic"
        :squadId="$squadId"
        :storyPoints="$storyPoints"
        :statuses="$statuses"
        :categories="$categories"
        :quarter="$quarter"
        :squadName="$squadName"
    />
    @endif
</div>
