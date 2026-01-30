@props(['status'])

@php
    $color = match($status->slug) {
        'completed' => 'green',
        'in-progress' => 'blue',
        'blocked' => 'red',
        default => 'zinc',
    };
@endphp

<flux:badge :color="$color" size="sm">{{ $status->name }}</flux:badge>
