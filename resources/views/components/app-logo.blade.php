@props([
    'sidebar' => false,
    'responsive' => false,
])

@php
    // Below `sm` the header shares a tight row with the drawer toggle, the
    // theme control, the bell and the profile chip. Collapse the lockup to
    // just the mark there. The name is only hidden visually (sr-only), so the
    // home link keeps its accessible name. `div:last-child` is flux:brand's
    // name element.
    $responsiveClass = $responsive ? 'max-sm:[&>div:last-child]:sr-only' : null;
@endphp

@if($sidebar)
    <flux:sidebar.brand name="Project Roadmap" :logo="asset('roadmap-icon.png')" {{ $attributes }} />
@else
    <flux:brand name="Project Roadmap" :logo="asset('roadmap-icon.png')" {{ $attributes->class($responsiveClass) }} />
@endif
