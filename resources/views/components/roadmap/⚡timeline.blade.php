<?php

use App\Models\Epic;
use App\Models\Squad;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app.sidebar')] class extends Component
{
    public string $start_date = '';
    public string $end_date = '';
    public array $selected_squads = [];

    public function mount(): void
    {
        $this->start_date = now()->startOfMonth()->format('Y-m-d');
        $this->end_date = now()->addMonths(6)->endOfMonth()->format('Y-m-d');
    }

    public function with(): array
    {
        $query = auth()->user()->currentTeam
            ->epics()
            ->with(['status', 'squads', 'stories.status', 'stories.squad'])
            ->whereNotNull('start_date')
            ->whereNotNull('end_date');

        if ($this->start_date) {
            $query->where('end_date', '>=', $this->start_date);
        }

        if ($this->end_date) {
            $query->where('start_date', '<=', $this->end_date);
        }

        if (!empty($this->selected_squads)) {
            $query->whereHas('squads', function ($q) {
                $q->whereIn('squads.id', $this->selected_squads);
            });
        }

        $epics = $query->orderBy('start_date')->get();

        $squads = auth()->user()->currentTeam->squads()->orderBy('name')->get();

        return [
            'epics' => $epics,
            'squads' => $squads,
        ];
    }
};
?>

<div>
    <div class="flex items-center justify-between pt-8 pb-10">
        <div class="flex items-center gap-4">
            <h1>Roadmap Timeline</h1>
            <x-roadmap-navigation currentView="timeline" />
        </div>
    </div>

    <flux:card class="mb-6">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <flux:field>
                    <flux:label>Start Date</flux:label>
                    <flux:input type="date" wire:model.live="start_date" />
                </flux:field>

                <flux:field>
                    <flux:label>End Date</flux:label>
                    <flux:input type="date" wire:model.live="end_date" />
                </flux:field>

                <flux:field>
                    <flux:label>Filter by Squads</flux:label>
                    <div class="flex flex-wrap gap-2 mt-2">
                        @foreach($squads as $squad)
                        <label class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border cursor-pointer transition-colors {{ in_array($squad->id, $selected_squads) ? 'border-zinc-400 dark:border-zinc-500 bg-zinc-100 dark:bg-zinc-800' : 'border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800' }}">
                            <input type="checkbox" wire:model.live="selected_squads" value="{{ $squad->id }}" class="rounded border-zinc-300 dark:border-zinc-700" />
                            <div class="h-3 w-3 rounded-full" style="background-color: {{ $squad->color }}"></div>
                            <span class="text-sm">{{ $squad->name }}</span>
                        </label>
                        @endforeach
                    </div>
                </flux:field>
            </div>
        </flux:card>

        @if($epics->isEmpty())
        <flux:card>
            <div class="text-center py-12">
                <flux:icon.calendar class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="lg" class="mt-4">No epics in this date range</flux:heading>
                <flux:text class="mt-2">Adjust your filters or create a new epic.</flux:text>
                <flux:button href="/epics/create" variant="primary" class="mt-6" wire:navigate>Create Epic</flux:button>
            </div>
        </flux:card>
        @else
        <div class="space-y-6">
            @foreach($epics as $epic)
            <flux:card>
                <div class="space-y-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1">
                            <div class="flex items-center gap-3 mb-2">
                                <flux:heading size="lg">{{ $epic->title }}</flux:heading>
                                <flux:badge :color="$epic->status->slug === 'completed' ? 'green' : ($epic->status->slug === 'in-progress' ? 'blue' : ($epic->status->slug === 'blocked' ? 'red' : 'zinc'))">
                                    {{ $epic->status->name }}
                                </flux:badge>
                            </div>
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ $epic->start_date->format('M j, Y') }} - {{ $epic->end_date->format('M j, Y') }}
                                ({{ $epic->start_date->diffInDays($epic->end_date) }} days)
                            </flux:text>
                            <div class="flex items-center gap-2 mt-2">
                                @foreach($epic->squads as $squad)
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-xs font-medium" style="background-color: {{ $squad->color }}20; color: {{ $squad->color }}">
                                    <div class="h-2 w-2 rounded-full" style="background-color: {{ $squad->color }}"></div>
                                    {{ $squad->name }}
                                </span>
                                @endforeach
                            </div>
                        </div>
                        <flux:button href="/epics/{{ $epic->id }}/edit" variant="ghost" size="sm" wire:navigate>Edit</flux:button>
                    </div>

                    <div class="w-full h-8 bg-zinc-100 dark:bg-zinc-800 rounded-lg overflow-hidden relative">
                        @php
                        $epicStart = max($epic->start_date, \Carbon\Carbon::parse($this->start_date));
                        $epicEnd = min($epic->end_date, \Carbon\Carbon::parse($this->end_date));
                        $rangeStart = \Carbon\Carbon::parse($this->start_date);
                        $rangeEnd = \Carbon\Carbon::parse($this->end_date);
                        $totalDays = $rangeStart->diffInDays($rangeEnd);
                        $offsetDays = $rangeStart->diffInDays($epicStart);
                        $durationDays = $epicStart->diffInDays($epicEnd);
                        $leftPercent = ($offsetDays / $totalDays) * 100;
                        $widthPercent = ($durationDays / $totalDays) * 100;
                        @endphp
                        <div class="absolute h-full rounded"
                            style="left: {{ $leftPercent }}%; width: {{ $widthPercent }}%; background-color: {{ $epic->squads->first()->color ?? '#6B7280' }}80">
                        </div>
                    </div>

                    @if($epic->stories->isNotEmpty())
                    <div class="pl-6 space-y-2 border-l-2 border-zinc-200 dark:border-zinc-700">
                        @foreach($epic->stories as $story)
                        <div class="flex items-center gap-3">
                            <div class="h-2 w-2 rounded-full" style="background-color: {{ $story->squad->color }}"></div>
                            <span class="flex-1 text-sm">{{ $story->title }}</span>
                            <flux:badge size="sm" :color="$story->status->slug === 'completed' ? 'green' : ($story->status->slug === 'in-progress' ? 'blue' : ($story->status->slug === 'blocked' ? 'red' : 'zinc'))">
                                {{ $story->status->name }}
                            </flux:badge>
                            @if($story->start_date && $story->end_date)
                            <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $story->start_date->format('M j') }} - {{ $story->end_date->format('M j') }}
                            </span>
                            @endif
                            <flux:button href="/stories/{{ $story->id }}/edit" variant="ghost" size="xs" wire:navigate>Edit</flux:button>
                        </div>
                        @endforeach
                    </div>
                    @endif
                </div>
            </flux:card>
            @endforeach
        </div>
        @endif
</div>