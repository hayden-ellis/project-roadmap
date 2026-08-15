@props([
    'engineer',
    'size' => 'xs',
    'tooltip' => true,
])

{{--
    One face, everywhere.

    Deliberately uncoloured: Flux's default is a quiet zinc, and tinting each
    avatar by squad turned a row of people into a row of highlighter pens. The
    squad still reads from the card's left edge, so nothing is lost by calming
    these down.

    A real photo appears when the engineer is linked to a user account that has
    one uploaded; otherwise initials.
--}}
<flux:avatar
    circle
    :size="$size"
    :name="$engineer->name"
    :src="$engineer->avatarUrl()"
    :tooltip="$tooltip ? $engineer->name : null"
    {{ $attributes }}
/>
