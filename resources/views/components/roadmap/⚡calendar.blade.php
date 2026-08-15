<?php

use App\Services\CapacityService;
use App\Support\Quarter;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Roadmap Gantt.
 *
 * Bars are derived from allocations rather than stored dates, so this view is
 * read-only by design: changing when work happens means changing who is on it,
 * which belongs in the planning grid. That is the whole point of dropping the
 * hand-typed epic_squad date range -- a roadmap that cannot drift from
 * staffing.
 */
new #[Layout('components.layouts.app.sidebar')] class extends Component
{
    #[Url]
    public array $selected_squads = [];

    #[Url]
    public string $quarter = '';

    #[Url]
    public int $quarters_shown = 2;

    public function mount(): void
    {
        $this->quarter = $this->quarter ?: Quarter::current()->key();
    }

    public function with(): array
    {
        $team = auth()->user()->currentTeam;
        $capacity = CapacityService::for($team);

        $from = Quarter::parse($this->quarter);
        $shown = $from->through(max(1, $this->quarters_shown));
        $rangeStart = $from->start();
        $rangeEnd = end($shown)->end();

        $query = $team->epics()->with(['category', 'status', 'quarterPlans.squad']);

        if (! empty($this->selected_squads)) {
            $query->whereHas('quarterPlans', fn ($q) => $q->whereIn('squad_id', $this->selected_squads));
        }

        $epics = $query->get();

        $squadFilter = count($this->selected_squads) === 1 ? $this->selected_squads[0] : null;
        $spans = $capacity->epicSpans($epics->pluck('id'), $squadFilter);

        $epics = $epics
            ->filter(fn ($e) => isset($spans[$e->id]))
            ->filter(fn ($e) => $spans[$e->id]['start'] <= $rangeEnd && $spans[$e->id]['end'] >= $rangeStart)
            ->sortBy(fn ($e) => $spans[$e->id]['start'])
            ->values()
            ->map(function ($epic) use ($spans) {
                $epic->span = $spans[$epic->id];

                return $epic;
            });

        // Month columns across the shown quarters.
        $months = [];
        $cursor = CarbonImmutable::parse($rangeStart)->startOfMonth();
        while ($cursor <= $rangeEnd) {
            $months[] = $cursor;
            $cursor = $cursor->addMonth();
        }

        return [
            'epics' => $epics,
            'squads' => $team->squads()->ordered()->get(),
            'months' => $months,
            'rangeStart' => CarbonImmutable::parse($rangeStart),
            'rangeEnd' => CarbonImmutable::parse($rangeEnd),
            'quarters' => Quarter::current()->previous()->through(8),
            'shownQuarters' => $shown,
            'today' => CarbonImmutable::now(),
        ];
    }
};
?>

<div>
    <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4 pb-6">
        <div>
            <h1>Roadmap</h1>
            <flux:text class="mt-1">Derived from who is booked — edit staffing in Planning.</flux:text>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <flux:select wire:model.live="quarter" class="w-40">
                @foreach($quarters as $option)
                <flux:select.option value="{{ $option->key() }}">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="quarters_shown" class="w-36">
                <flux:select.option value="1">1 quarter</flux:select.option>
                <flux:select.option value="2">2 quarters</flux:select.option>
                <flux:select.option value="4">1 year</flux:select.option>
            </flux:select>
        </div>
    </div>

    <x-roadmap-navigation />

    <div class="flex flex-wrap items-center gap-2 my-6">
        @foreach($squads as $squad)
        <label class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border cursor-pointer transition-colors {{ in_array($squad->id, $selected_squads) ? 'border-zinc-400 dark:border-zinc-500 bg-zinc-100 dark:bg-zinc-800' : 'border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800' }}">
            <input type="checkbox" wire:model.live="selected_squads" value="{{ $squad->id }}" class="rounded border-zinc-300 dark:border-zinc-700" />
            <div class="h-2.5 w-2.5 rounded-full" style="background-color: {{ $squad->color }}"></div>
            <span class="text-sm">{{ $squad->name }}</span>
        </label>
        @endforeach
    </div>

    @if($epics->isEmpty())
    <flux:card>
        <div class="text-center py-12">
            <flux:icon.calendar class="mx-auto h-12 w-12 text-zinc-400" />
            <flux:heading size="lg" class="mt-4">Nothing staffed in this range</flux:heading>
            <flux:text class="mt-2">Assign engineers to epics in Planning and the roadmap fills itself in.</flux:text>
        </div>
    </flux:card>
    @else
    @php
        $totalDays = max(1, $rangeStart->diffInDays($rangeEnd));
        $todayPercent = $today->between($rangeStart, $rangeEnd)
            ? ($rangeStart->diffInDays($today) / $totalDays) * 100
            : null;
    @endphp

    <flux:card class="overflow-x-auto">
        <div class="min-w-[860px]">
            {{-- Month header --}}
            <div class="flex border-b border-zinc-200 dark:border-zinc-700 pb-2 mb-3">
                <div class="w-64 shrink-0 text-xs font-medium text-zinc-500 dark:text-zinc-400">Epic</div>
                <div class="flex-1 relative flex">
                    @foreach($months as $month)
                    @php
                        $monthDays = $month->daysInMonth;
                        $monthWidth = ($monthDays / $totalDays) * 100;
                    @endphp
                    <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 border-l border-zinc-200 dark:border-zinc-700 pl-2 overflow-hidden whitespace-nowrap"
                         style="width: {{ $monthWidth }}%">
                        {{ $month->format('M') }}
                        @if($month->month === 1 || $loop->first)<span class="text-zinc-400">{{ $month->format('y') }}</span>@endif
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- Rows --}}
            <div class="space-y-2 relative">
                @if($todayPercent !== null)
                <div class="absolute top-0 bottom-0 w-px bg-red-500/60 z-10 pointer-events-none"
                     style="left: calc(16rem + {{ $todayPercent }}% * (100% - 16rem) / 100)"></div>
                @endif

                @foreach($epics as $epic)
                @php
                    $barStart = $epic->span['start']->max($rangeStart);
                    $barEnd = $epic->span['end']->min($rangeEnd);
                    $offsetDays = $rangeStart->diffInDays($barStart);
                    $spanDays = max(1, $barStart->diffInDays($barEnd));
                    $leftPercent = ($offsetDays / $totalDays) * 100;
                    $widthPercent = max(1.5, ($spanDays / $totalDays) * 100);
                    $squad = $epic->quarterPlans->first()?->squad;
                    $color = $squad->color ?? '#6B7280';
                @endphp

                <div class="flex items-center group">
                    <div class="w-64 shrink-0 pr-4 min-w-0">
                        <a href="/epics/{{ $epic->id }}/edit" wire:navigate
                           class="block text-sm font-medium truncate hover:underline">{{ $epic->title }}</a>
                        <div class="flex items-center gap-1.5 mt-0.5">
                            @if($epic->status)
                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium"
                                  style="background-color: {{ $epic->status->color }}1f; color: {{ $epic->status->color }}">
                                <span class="size-1.5 rounded-full" style="background-color: {{ $epic->status->color }}"></span>
                                {{ $epic->status->name }}
                            </span>
                            @endif
                            <span class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ $epic->span['points'] }} pts</span>
                        </div>
                    </div>

                    <div class="flex-1 relative h-8 rounded-md bg-zinc-50 dark:bg-zinc-800/50">
                        <div class="absolute h-full rounded-md flex items-center px-2 overflow-hidden transition-opacity group-hover:opacity-90"
                             style="left: {{ $leftPercent }}%; width: {{ $widthPercent }}%; background-color: {{ $color }}80"
                             title="{{ $epic->title }} — {{ $epic->span['start']->format('M j') }} to {{ $epic->span['end']->format('M j, Y') }} · {{ $epic->span['weeks'] }} weeks · {{ $epic->span['points'] }} pts">
                            <span class="text-[11px] font-medium text-zinc-900 dark:text-white whitespace-nowrap">
                                {{ $epic->span['weeks'] }}w
                            </span>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </flux:card>
    @endif
</div>
