@props(['priority'])

@php
    // Jira-style glyph: direction carries the rank, colour repeats it. The
    // word survives as a tooltip and for screen readers. Glyphs are Lucide,
    // imported via `php artisan flux:icon`.
    $config = [
        'critical' => ['label' => 'Critical', 'color' => 'text-red-600 dark:text-red-400'],
        'high' => ['label' => 'High', 'color' => 'text-orange-600 dark:text-orange-400'],
        'medium' => ['label' => 'Medium', 'color' => 'text-amber-600 dark:text-amber-400'],
        'low' => ['label' => 'Low', 'color' => 'text-blue-600 dark:text-blue-400'],
    ][$priority] ?? ['label' => ucfirst($priority ?? 'None'), 'color' => 'text-zinc-400'];
@endphp

<flux:tooltip content="{{ $config['label'] }} priority">
    <span {{ $attributes->class(['inline-flex shrink-0', $config['color']]) }}>
        @switch($priority)
            @case('critical')
                <flux:icon.chevrons-up variant="micro" />
                @break
            @case('high')
                <flux:icon.chevron-up variant="micro" />
                @break
            @case('medium')
                <flux:icon.equal variant="micro" />
                @break
            @default
                <flux:icon.chevron-down variant="micro" />
        @endswitch
        <span class="sr-only">{{ $config['label'] }} priority</span>
    </span>
</flux:tooltip>
