@props(['color', 'size' => 'md'])

@php
$fluxColors = ['zinc', 'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose'];
$isFluxColor = in_array($color, $fluxColors);

$sizeClasses = match($size) {
    'sm' => 'h-3 w-3',
    'md' => 'h-4 w-4',
    'lg' => 'h-12 w-12',
    default => 'h-4 w-4',
};
@endphp

@if($isFluxColor)
    <flux:badge color="{{ $color }}" {{ $attributes->merge(['class' => $sizeClasses]) }} />
@else
    <div {{ $attributes->merge(['class' => "rounded-lg $sizeClasses"]) }} style="background-color: {{ $color }}"></div>
@endif

