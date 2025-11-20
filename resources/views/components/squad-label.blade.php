@props(['squad'])

@php
$fluxColors = ['zinc', 'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose'];
$isFluxColor = in_array($squad->color, $fluxColors);
@endphp

@if($isFluxColor)
    <flux:badge color="{{ $squad->color }}" size="sm" {{ $attributes }}>
        {{ $squad->name }}
    </flux:badge>
@else
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-xs font-medium']) }} style="background-color: {{ $squad->color }}20; color: {{ $squad->color }}">
        <div class="h-1.5 w-1.5 rounded-full" style="background-color: {{ $squad->color }}"></div>
        {{ $squad->name }}
    </span>
@endif

