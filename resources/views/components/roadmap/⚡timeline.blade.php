<?php

use App\Services\CapacityService;
use App\Support\Quarter;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('components.layouts.app.sidebar')] class extends Component
{
    #[Url]
    public array $selected_squads = [];

    #[Url]
    public string $quarter = '';

    public function mount(): void
    {
        $this->quarter = $this->quarter ?: Quarter::current()->key();
    }

    public function with(): array
    {
        $team = auth()->user()->currentTeam;
        $capacity = CapacityService::for($team);
        $quarter = Quarter::parse($this->quarter);

        $query = $team->epics()->with(['category', 'status', 'quarterPlans.squad']);

        if (! empty($this->selected_squads)) {
            $query->whereHas('quarterPlans', fn ($q) => $q->whereIn('squad_id', $this->selected_squads));
        }

        $epics = $query->get();
        $spans = $capacity->epicSpans($epics->pluck('id'));

        // Only epics with real staffing can be drawn on a timeline.
        $epics = $epics
            ->filter(fn ($e) => isset($spans[$e->id]))
            ->sortBy(fn ($e) => $spans[$e->id]['start'])
            ->values()
            ->map(function ($epic) use ($spans) {
                $epic->span = $spans[$epic->id];

                return $epic;
            });

        $rangeStart = $epics->min(fn ($e) => $e->span['start']) ?? $quarter->start();
        $rangeEnd = $epics->max(fn ($e) => $e->span['end']) ?? $quarter->end();

        return [
            'epics' => $epics,
            'squads' => $team->squads()->ordered()->get(),
            'rangeStart' => CarbonImmutable::parse($rangeStart),
            'rangeEnd' => CarbonImmutable::parse($rangeEnd),
            'quarters' => Quarter::current()->previous()->through(6),
            'currentWeek' => $capacity->currentWeek(),
        ];
    }
};
?>

<div>
    <div class="pt-8 pb-6">
        <h1>Timeline</h1>
        <flux:text class="mt-1">Bars show when people are actually booked, not planned dates.</flux:text>
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
            <flux:heading size="lg" class="mt-4">Nothing staffed yet</flux:heading>
            <flux:text class="mt-2">Assign engineers to epics in Planning and they'll appear here.</flux:text>
        </div>
    </flux:card>
    @else
    @php
        $totalDays = max(1, $rangeStart->diffInDays($rangeEnd));
    @endphp

    <flux:card class="overflow-x-auto">
        <div class="min-w-[720px] space-y-3">
            <div class="flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400 pb-2 border-b border-zinc-200 dark:border-zinc-700">
                <span>{{ $rangeStart->format('M j, Y') }}</span>
                <span>{{ $rangeEnd->format('M j, Y') }}</span>
            </div>

            @foreach($epics as $epic)
            @php
                $offsetDays = $rangeStart->diffInDays($epic->span['start']);
                $spanDays = max(1, $epic->span['start']->diffInDays($epic->span['end']));
                $leftPercent = ($offsetDays / $totalDays) * 100;
                $widthPercent = max(2, ($spanDays / $totalDays) * 100);
                $squad = $epic->quarterPlans->first()?->squad;
                $color = $squad->color ?? '#6B7280';
            @endphp

            <div class="space-y-1.5">
                <div class="flex flex-wrap items-center gap-2">
                    <a href="/epics/{{ $epic->id }}/edit" wire:navigate class="font-medium text-sm hover:underline">
                        {{ $epic->title }}
                    </a>
                    @if($epic->status)
                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-[11px] font-medium"
                          style="background-color: {{ $epic->status->color }}1f; color: {{ $epic->status->color }}">
                        <span class="size-1.5 rounded-full" style="background-color: {{ $epic->status->color }}"></span>
                        {{ $epic->status->name }}
                    </span>
                    @endif
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ $epic->span['weeks'] }} {{ Str::plural('week', $epic->span['weeks']) }} · {{ $epic->span['points'] }} pts
                    </span>
                </div>

                <div class="relative h-7 rounded-md bg-zinc-100 dark:bg-zinc-800">
                    <div class="absolute h-full rounded-md flex items-center px-2 overflow-hidden"
                         style="left: {{ $leftPercent }}%; width: {{ $widthPercent }}%; background-color: {{ $color }}80"
                         title="{{ $epic->span['start']->format('M j') }} – {{ $epic->span['end']->format('M j, Y') }}">
                        <span class="text-[11px] font-medium text-zinc-900 dark:text-white whitespace-nowrap">
                            {{ $epic->span['start']->format('M j') }} – {{ $epic->span['end']->format('M j') }}
                        </span>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </flux:card>
    @endif
</div>
