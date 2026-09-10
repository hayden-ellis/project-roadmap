@props(['count' => 0, 'unread' => false])

{{--
    How much has been said on an epic, for a board card.

    Grey when it is just a number. The accent when the thread holds something
    you have not read -- a mention or a reply -- which is the only time a
    count on a board is a reason to open the card rather than a statistic.
--}}
@php
    $label = $count.' '.Str::plural('comment', $count).($unread ? ', new for you' : '');
@endphp

<span title="{{ $label }}"
      {{ $attributes->class([
          'inline-flex items-center gap-1 text-[10px] font-medium tabular-nums shrink-0',
          'text-accent' => $unread,
          'text-zinc-400 dark:text-zinc-500' => ! $unread,
      ]) }}>
    <flux:icon.chat-bubble-left variant="micro" class="size-3" />
    {{ $count }}
    <span class="sr-only">{{ $label }}</span>
</span>
