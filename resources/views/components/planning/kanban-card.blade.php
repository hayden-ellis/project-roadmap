@props([
    'epic',
    'squadId',
    'planned' => false,
    'statuses' => null,
    'categories' => null,
    'quarter' => null,
    'squadName' => null,
    'isConsidering' => null,
    'sortable' => true,
])

@php
    $borderColor = $planned ? 'border-green-200 dark:border-green-900' : 'border-zinc-200 dark:border-zinc-700';
    $committedPoints = $planned ? ($epic->planned_story_points ?? null) : ($epic->existing_story_points ?? null);
    $estimatedPoints = $epic->estimated_story_points ?? null;
    $showConsideringToggle = !$planned && $isConsidering !== null;
@endphp

<div
    @if($sortable) x-sort:item="{{ $epic->id }}" @endif
    wire:key="{{ $planned ? 'planned' : 'backlog' }}-{{ $epic->id }}"
    wire:transition
>
    <div
        @if($statuses && $categories)
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
        {{-- Add button (top right, hover only) --}}
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

            <div class="flex items-center gap-1.5">
                {{-- Star toggle for backlog items --}}
                @if($showConsideringToggle)
                <button
                    type="button"
                    wire:click.stop="toggleConsideration({{ $epic->id }}, {{ $squadId }})"
                    class="{{ $isConsidering ? 'text-amber-500' : 'opacity-0 group-hover:opacity-100 text-zinc-400 hover:text-amber-500' }} p-0.5 rounded hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-all"
                >
                    @if($isConsidering)
                    <flux:icon.star variant="solid" class="size-4" />
                    @else
                    <flux:icon.star variant="outline" class="size-4" />
                    @endif
                </button>
                @endif

                @if($planned)
                    @if($committedPoints && $estimatedPoints && $committedPoints < $estimatedPoints)
                        {{-- Partial commitment: show committed / total --}}
                        <flux:badge color="amber" size="sm">{{ $committedPoints }} / {{ $estimatedPoints }} pts</flux:badge>
                    @elseif($committedPoints)
                        {{-- Full commitment or no estimate --}}
                        <flux:badge color="blue" size="sm">{{ $committedPoints }} pts</flux:badge>
                    @else
                        <flux:badge color="zinc" size="sm">– pts</flux:badge>
                    @endif
                @else
                    {{-- Backlog: show estimate (total) --}}
                    <flux:badge color="{{ $estimatedPoints ? 'blue' : 'zinc' }}" size="sm">{{ $estimatedPoints ? $estimatedPoints . ' pts' : '– pts' }}</flux:badge>
                @endif
            </div>
        </div>
    </div>

    @if($statuses && $categories)
    <x-planning.epic-modal
        :epic="$epic"
        :squadId="$squadId"
        :storyPoints="$committedPoints"
        :estimatedPoints="$estimatedPoints"
        :statuses="$statuses"
        :categories="$categories"
        :quarter="$quarter"
        :squadName="$squadName"
    />
    @endif
</div>
