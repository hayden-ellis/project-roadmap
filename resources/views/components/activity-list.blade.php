{{--
    An epic's history: one row per save, newest first, worded by
    EpicActivity::lines() so the flyout, the epic page and the MCP payload
    all tell the same story. Rows are shaped like comment rows so the two
    tabs read as one thing.
--}}
@props([
    'activities',
    /** Rows on the epic in total; when more than shown, offers "Show all". */
    'total' => null,
    'size' => 'xs',
])

@php
    $name = $size === 'sm' ? 'text-sm font-medium' : 'text-[13px] font-medium';
    $body = $size === 'sm' ? 'text-sm leading-relaxed text-zinc-700 dark:text-zinc-300' : 'text-[13px] leading-5 text-zinc-700 dark:text-zinc-300';
@endphp

<div {{ $attributes->class(['space-y-4']) }}>
    @forelse($activities as $activity)
    <div wire:key="activity-{{ $activity->id }}" class="flex gap-{{ $size === 'sm' ? '3' : '2.5' }}">
        @if($activity->isSystem())
        <flux:avatar circle :size="$size" icon="cog-6-tooth" />
        @else
        <flux:avatar circle :size="$size" :name="$activity->actorName()" :src="$activity->user?->profile_photo_url" />
        @endif

        <div class="flex-1 min-w-0">
            <div class="flex items-baseline gap-2">
                <span class="{{ $name }} truncate">{{ $activity->actorName() }}</span>
                @if($activity->source === 'mcp')
                <span class="text-[10px] font-semibold uppercase tracking-wide px-1.5 py-px rounded bg-zinc-100 dark:bg-zinc-700 text-zinc-500 dark:text-zinc-300 shrink-0" title="Made through the MCP server">MCP</span>
                @endif
                <span class="text-[11px] text-zinc-400 dark:text-zinc-500 shrink-0" title="{{ $activity->created_at->toDayDateTimeString() }}">{{ $activity->created_at->diffForHumans() }}</span>
            </div>

            @foreach($activity->lines() as $line)
            <div class="{{ $body }}">
                {{ $line['text'] }}

                @if($line['long'] && ($line['from'] !== null || $line['to'] !== null))
                {{-- A description is too much to inline; it opens on demand. --}}
                <details class="mt-1">
                    <summary class="cursor-pointer text-[11px] font-medium text-zinc-400 dark:text-zinc-500 hover:text-zinc-600 dark:hover:text-zinc-300 select-none">Show text</summary>
                    <div class="mt-1.5 space-y-2">
                        @if($line['from'] !== null)
                        <div>
                            <div class="text-[10px] font-semibold uppercase tracking-[0.14em] text-zinc-400 dark:text-zinc-500">Before</div>
                            <div class="text-xs whitespace-pre-line line-clamp-4 text-zinc-500 dark:text-zinc-400">{{ Str::limit($line['from'], 300) }}</div>
                        </div>
                        @endif
                        @if($line['to'] !== null)
                        <div>
                            <div class="text-[10px] font-semibold uppercase tracking-[0.14em] text-zinc-400 dark:text-zinc-500">After</div>
                            <div class="text-xs whitespace-pre-line line-clamp-4 text-zinc-700 dark:text-zinc-300">{{ Str::limit($line['to'], 300) }}</div>
                        </div>
                        @endif
                    </div>
                </details>
                @endif
            </div>
            @endforeach
        </div>
    </div>
    @empty
    <flux:text class="text-sm">Nothing has changed yet.</flux:text>
    @endforelse

    @if($total !== null && $total > $activities->count())
    <div class="pt-1">
        <flux:button size="xs" variant="ghost" wire:click="showAllHistory">Show all {{ $total }}</flux:button>
    </div>
    @endif
</div>
