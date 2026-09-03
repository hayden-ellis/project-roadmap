<?php

use App\Models\Allocation;
use App\Services\CapacityService;
use App\Support\DefaultSquad;
use App\Support\Quarter;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('components.layouts.app.sidebar')] class extends Component
{
    #[Url]
    public string $quarter = '';

    #[Url]
    public bool $showInactive = false;

    #[Url]
    public array $statusIds = [];

    #[Url]
    public array $squadIds = [];

    public function mount(): void
    {
        $this->quarter = $this->quarter ?: Quarter::current()->key();
        $this->squadIds = DefaultSquad::seed($this->squadIds, 'squadIds', Auth::user(), Auth::user()->currentTeam);

        // Fresh visits start with completed work filtered out; an explicit
        // selection (including clearing to "all") travels in the URL.
        $this->statusIds = $this->statusIds ?: Auth::user()->currentTeam
            ->statuses()->where('is_complete', false)
            ->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function with(): array
    {
        $team = Auth::user()->currentTeam;
        $capacity = CapacityService::for($team);
        $quarter = Quarter::parse($this->quarter);
        $week = $capacity->currentWeek();

        $engineers = $team->engineers()
            ->with('squad')
            ->when(! $this->showInactive, fn ($q) => $q->active())
            ->when(! empty($this->squadIds), fn ($q) => $q->whereIn('squad_id', $this->squadIds))
            ->ordered()
            ->get();

        // One query for every engineer's bookings this quarter, grouped per
        // epic so each row can list what its engineer is actually on.
        $allocations = Allocation::whereIn('engineer_id', $engineers->pluck('id'))
            ->whereIn('week_start', collect($capacity->calendar()->weeksIn($quarter))->map->toDateString())
            ->with('epic.status')
            ->get()
            ->groupBy('engineer_id');

        $engineers = $engineers
            ->map(function ($engineer) use ($capacity, $quarter, $week, $allocations) {
                $engineer->epics = ($allocations[$engineer->id] ?? collect())
                    ->filter(fn ($a) => $a->epic)
                    ->when(! empty($this->statusIds), fn ($c) => $c->filter(fn ($a) => in_array((string) $a->epic->status_id, $this->statusIds, true)))
                    ->groupBy('epic_id')
                    ->map(fn ($rows) => $rows->first()->epic)
                    ->sortBy(fn ($epic) => ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3][$epic->priority] ?? 4)
                    ->values();

                $engineer->stats = [
                    'capacity' => $capacity->quarterCapacity($engineer, $quarter),
                    'planned' => $capacity->plannedQuarterCapacity($engineer, $quarter),
                    'allocated' => $capacity->quarterAllocatedPoints($engineer, $quarter),
                    'remaining' => $capacity->remainingPoints($engineer, $quarter),
                    'thisWeek' => $capacity->allocatedShare($engineer, $week),
                    'weekPoints' => $capacity->weeklyCapacity($engineer, $week),
                    'over' => $capacity->isOverAllocated($engineer, $week),
                ];

                return $engineer;
            });

        return [
            'engineers' => $engineers,
            'squads' => $team->squads()->orderBy('name')->get(),
            'statuses' => $team->statuses()->ordered()->get(),
            'quarters' => Quarter::current()->previous()->through(8),
            'quarterLabel' => $quarter->label(),
        ];
    }
};
?>

<div>
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 pt-8 pb-6">
        <div>
            <h1>Engineers</h1>
            <flux:text class="mt-1">Capacity and commitments for {{ $quarterLabel }}</flux:text>
        </div>
        <div class="flex items-center gap-3">
            <flux:select wire:model.live="quarter" class="w-40">
                @foreach($quarters as $option)
                <flux:select.option value="{{ $option->key() }}">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button href="/engineers/create" icon="plus" wire:navigate>Add Engineer</flux:button>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-x-6 gap-y-3 mb-4">
        <div class="w-56 shrink-0">
            <flux:select multiple variant="listbox" wire:model.live="squadIds" placeholder="All squads">
                @foreach($squads as $squad)
                <flux:select.option value="{{ $squad->id }}">{{ $squad->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div class="w-56 shrink-0">
            <flux:select multiple variant="listbox" wire:model.live="statusIds" placeholder="All statuses">
                @foreach($statuses as $status)
                <flux:select.option value="{{ $status->id }}">{{ $status->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <flux:switch wire:model.live="showInactive" label="Show inactive" class="whitespace-nowrap" />

        <livewire:default-squad :selected="count($squadIds) === 1 ? (int) $squadIds[0] : null" />
    </div>

    @if($engineers->isEmpty())
    <flux:card>
        <div class="text-center py-12">
            <flux:icon.users class="mx-auto h-12 w-12 text-zinc-400" />
            <flux:heading size="lg" class="mt-4">{{ empty($squadIds) ? 'No engineers yet' : 'Nobody in this squad' }}</flux:heading>
            <flux:text class="mt-2">{{ empty($squadIds) ? 'Add your roster to start planning capacity.' : 'Pick another squad, or clear the filter to see everyone.' }}</flux:text>
            @if(empty($squadIds))
            <flux:button href="/engineers/create" variant="primary" class="mt-6" wire:navigate>Add Engineer</flux:button>
            @else
            <flux:button wire:click="$set('squadIds', [])" variant="primary" class="mt-6">Show everyone</flux:button>
            @endif
        </div>
    </flux:card>
    @else
    <flux:card class="overflow-x-auto p-0">
        <table class="w-full min-w-[720px] text-sm">
            <thead class="border-b border-zinc-200 dark:border-zinc-700">
                <tr class="text-left text-xs text-zinc-500 dark:text-zinc-400">
                    <th class="px-4 py-3 font-medium">Engineer</th>
                    <th class="px-4 py-3 font-medium">Squad</th>
                    <th class="px-4 py-3 font-medium text-right">Capacity</th>
                    <th class="px-4 py-3 font-medium text-right">Allocated</th>
                    <th class="px-4 py-3 font-medium text-right">Remaining</th>
                    <th class="px-4 py-3 font-medium">This week</th>
                </tr>
            </thead>
            <tbody x-data="{ collapsed: {} }">
                @foreach($engineers as $engineer)
                {{-- An engineer and their epics render as two <tr>s but must read as
                     one unit: the border sits only between engineers, and hovering
                     either row tints both. --}}
                <tr class="{{ $loop->first ? '' : 'border-t border-zinc-200 dark:border-zinc-700' }} cursor-pointer
                           hover:bg-zinc-50 dark:hover:bg-zinc-800/50
                           @if($engineer->epics->isNotEmpty())
                           [&:hover+tr]:bg-zinc-50 dark:[&:hover+tr]:bg-zinc-800/50
                           has-[+tr:hover]:bg-zinc-50 dark:has-[+tr:hover]:bg-zinc-800/50
                           @endif"
                    onclick="window.location='/engineers/{{ $engineer->id }}/edit'">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            @if($engineer->epics->isNotEmpty())
                            <button type="button"
                                    class="shrink-0 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300"
                                    @click.stop="collapsed[{{ $engineer->id }}] = !collapsed[{{ $engineer->id }}]"
                                    :aria-expanded="!collapsed[{{ $engineer->id }}]"
                                    aria-label="Toggle epics for {{ $engineer->name }}">
                                <flux:icon.chevron-down class="size-4 transition-transform"
                                                        ::class="collapsed[{{ $engineer->id }}] && '-rotate-90'" />
                            </button>
                            @else
                            <span class="w-4 shrink-0"></span>
                            @endif
                            <x-engineer-avatar :engineer="$engineer" size="sm" :tooltip="false" />
                            <div class="min-w-0">
                                <div class="font-medium truncate flex items-center gap-2">
                                    {{ $engineer->name }}
                                    @unless($engineer->is_active)
                                    <flux:badge color="zinc" size="sm">Inactive</flux:badge>
                                    @endunless
                                </div>
                                @if($engineer->title)
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $engineer->title }}</div>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        @if($engineer->squad)
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-xs font-medium"
                              style="background-color: {{ $engineer->squad->color }}20; color: {{ $engineer->squad->color }}">
                            {{ $engineer->squad->name }}
                        </span>
                        @else
                        <span class="text-zinc-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums">
                        {{ $engineer->stats['capacity'] }}
                        @if($engineer->stats['planned'] !== null && $engineer->stats['planned'] !== $engineer->stats['capacity'])
                        <span class="text-xs text-amber-600 dark:text-amber-400 ml-1"
                              title="Planned {{ $engineer->stats['planned'] }}, reduced by time off">
                            ↓{{ $engineer->stats['planned'] - $engineer->stats['capacity'] }}
                        </span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ $engineer->stats['allocated'] }}</td>
                    <td class="px-4 py-3 text-right tabular-nums {{ $engineer->stats['remaining'] < 0 ? 'text-red-600 dark:text-red-400 font-semibold' : '' }}">
                        {{ $engineer->stats['remaining'] }}
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <div class="w-16 h-1.5 rounded-full bg-zinc-200 dark:bg-zinc-700 overflow-hidden">
                                <div class="h-full rounded-full {{ $engineer->stats['over'] ? 'bg-red-500' : 'bg-emerald-500' }}"
                                     style="width: {{ min(100, $engineer->stats['thisWeek'] * 100) }}%"></div>
                            </div>
                            <span class="text-xs tabular-nums {{ $engineer->stats['over'] ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-zinc-500 dark:text-zinc-400' }}">
                                {{ (int) round($engineer->stats['thisWeek'] * 100) }}%
                            </span>
                        </div>
                    </td>
                </tr>
                @if($engineer->epics->isNotEmpty())
                <tr x-show="!collapsed[{{ $engineer->id }}]" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                    <td colspan="6" class="px-4 pb-3.5 pt-0">
                        {{-- ml lines the lane up under the avatar: chevron (16) + gap (12). --}}
                        <div class="ml-7 flex flex-wrap gap-2 rounded-lg bg-zinc-100/70 dark:bg-zinc-800/60 px-3 py-2.5">
                            @foreach($engineer->epics as $epic)
                            <a href="/epics/{{ $epic->id }}/edit" wire:navigate
                               class="inline-flex items-center gap-2.5 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 py-1.5 pl-2.5 pr-3 hover:border-zinc-300 dark:hover:border-zinc-600">
                                <span class="w-[3px] self-stretch rounded-full shrink-0"
                                      style="background-color: {{ $epic->status?->color ?? '#a1a1aa' }}"></span>
                                <span class="min-w-0">
                                    <span class="block text-xs font-medium text-zinc-800 dark:text-zinc-100 truncate max-w-[16rem]">{{ $epic->title }}</span>
                                    <span class="block text-[11px] text-zinc-500 dark:text-zinc-400 leading-tight">{{ $epic->status?->name ?? 'No status' }}</span>
                                </span>
                                <x-priority-icon :priority="$epic->priority" />
                            </a>
                            @endforeach
                        </div>
                    </td>
                </tr>
                @endif
                @endforeach
            </tbody>
        </table>
    </flux:card>
    @endif
</div>
