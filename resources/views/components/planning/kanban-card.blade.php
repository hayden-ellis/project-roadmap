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
    {{ $attributes->class([
        'group p-3 bg-white dark:bg-zinc-900 rounded-lg shadow-sm hover:shadow-md cursor-grab active:cursor-grabbing',
        'border',
        $borderColor,
    ]) }}
>
    <div class="flex items-start justify-between gap-2">
        <div class="flex-1 min-w-0">
            @if($planned && $statuses && $categories)
            {{-- Planned: clickable title opens modal --}}
            <flux:button
                variant="ghost"
                size="sm"
                class="-ml-2 font-medium text-sm text-left truncate max-w-full"
                x-on:click.stop="$dispatch('modal-show', { name: 'edit-epic-{{ $epic->id }}' })"
            >
                <span class="truncate">{{ $epic->title }}</span>
                <flux:icon.pencil-square variant="micro" class="ml-1 opacity-0 group-hover:opacity-50 shrink-0" />
            </flux:button>
            <x-planning.epic-modal
                :epic="$epic"
                :squadId="$squadId"
                :storyPoints="$storyPoints"
                :statuses="$statuses"
                :categories="$categories"
                :quarter="$quarter"
                :squadName="$squadName"
            />
            @else
            {{-- Backlog: plain title --}}
            <flux:text class="font-medium text-sm truncate">{{ $epic->title }}</flux:text>
            @endif

            <div class="flex flex-wrap items-center gap-1.5 mt-1.5">
                <x-planning.status-badge :status="$epic->status" />

                @if($epic->category)
                <x-planning.category-badge :category="$epic->category" />
                @endif

                @if($storyPoints && !$planned)
                <flux:badge color="blue" size="sm">{{ $storyPoints }} pts</flux:badge>
                @endif
            </div>
        </div>

        {{-- Actions --}}
        <div class="flex items-center gap-2 shrink-0">
            @if($planned)
            <flux:badge color="blue" size="sm">{{ $storyPoints }} pts</flux:badge>
            <flux:button
                variant="ghost"
                size="xs"
                icon="x-mark"
                wire:click="removeEpicFromPlan({{ $epic->id }}, {{ $squadId }})"
                class="text-red-500 opacity-0 group-hover:opacity-100"
            />
            @else
            <flux:button
                variant="primary"
                size="xs"
                icon="plus"
                wire:click="addEpicToPlan({{ $epic->id }}, [{{ $squadId }}])"
                class="shrink-0 opacity-0 group-hover:opacity-100"
            >
                Add
            </flux:button>
            @endif
        </div>
    </div>
</div>
