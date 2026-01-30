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
        'group relative p-3 bg-white dark:bg-zinc-900 rounded-lg shadow-sm hover:shadow-md cursor-grab active:cursor-grabbing',
        'border',
        $borderColor,
    ]) }}
>
    {{-- Actions (absolute positioned) --}}
    <div class="absolute top-1 right-1 opacity-0 group-hover:opacity-100">
        @if($planned)
        <flux:button
            variant="ghost"
            size="xs"
            icon="x-mark"
            wire:click="removeEpicFromPlan({{ $epic->id }}, {{ $squadId }})"
            class="text-red-500"
        />
        @else
        <flux:button
            variant="primary"
            size="xs"
            icon="plus"
            wire:click="addEpicToPlan({{ $epic->id }}, [{{ $squadId }}])"
        >
            Add
        </flux:button>
        @endif
    </div>

    @if($planned && $statuses && $categories)
    {{-- Planned: clickable title opens modal --}}
    <flux:button
        variant="ghost"
        size="sm"
        class="-ml-3 font-medium text-sm text-left truncate max-w-full"
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

    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400 line-clamp-1 h-4">{{ $epic->description ?: '' }}</p>

    <div class="flex items-center justify-between gap-1.5 mt-2">
        <div class="flex flex-wrap items-center gap-1.5">
            <x-planning.status-badge :status="$epic->status" />

            @if($epic->category)
            <x-planning.category-badge :category="$epic->category" />
            @endif
        </div>

        @if($storyPoints)
        <flux:badge color="blue" size="sm">{{ $storyPoints }} pts</flux:badge>
        @endif
    </div>
</div>
