<?php

use App\Services\CapacityService;
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

    public function mount(): void
    {
        $this->quarter = $this->quarter ?: Quarter::current()->key();
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
            ->ordered()
            ->get()
            ->map(function ($engineer) use ($capacity, $quarter, $week) {
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
            'quarters' => Quarter::current()->previous()->through(8),
            'quarterLabel' => $quarter->label(),
            'squadTotals' => $engineers->groupBy(fn ($e) => $e->squad?->name ?? 'No squad')
                ->map(fn ($group) => [
                    'squad' => $group->first()->squad,
                    'capacity' => $group->sum(fn ($e) => $e->stats['capacity']),
                    'allocated' => $group->sum(fn ($e) => $e->stats['allocated']),
                    'count' => $group->count(),
                ]),
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

    {{-- Squad rollups: derived, never typed in --}}
    @if($squadTotals->isNotEmpty())
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-6">
        @foreach($squadTotals as $name => $total)
        @php $over = $total['allocated'] > $total['capacity']; @endphp
        <flux:card class="py-3 px-4">
            <div class="flex items-center gap-2 mb-2">
                @if($total['squad'])
                <div class="h-2.5 w-2.5 rounded-full" style="background-color: {{ $total['squad']->color }}"></div>
                @endif
                <flux:text class="font-medium">{{ $name }}</flux:text>
                <flux:text class="text-xs">{{ $total['count'] }} {{ Str::plural('engineer', $total['count']) }}</flux:text>
            </div>
            <div class="flex items-baseline gap-2">
                <span class="text-xl font-semibold {{ $over ? 'text-red-600 dark:text-red-400' : '' }}">
                    {{ $total['allocated'] }}
                </span>
                <flux:text class="text-sm">/ {{ $total['capacity'] }} pts</flux:text>
                @if($over)
                <flux:badge color="red" size="sm">Over</flux:badge>
                @endif
            </div>
            <div class="mt-2 h-1.5 rounded-full bg-zinc-200 dark:bg-zinc-700 overflow-hidden">
                <div class="h-full rounded-full {{ $over ? 'bg-red-500' : 'bg-emerald-500' }}"
                     style="width: {{ $total['capacity'] > 0 ? min(100, ($total['allocated'] / $total['capacity']) * 100) : 0 }}%"></div>
            </div>
        </flux:card>
        @endforeach
    </div>
    @endif

    <div class="flex items-center gap-2 mb-4">
        <flux:switch wire:model.live="showInactive" label="Show inactive" />
    </div>

    @if($engineers->isEmpty())
    <flux:card>
        <div class="text-center py-12">
            <flux:icon.users class="mx-auto h-12 w-12 text-zinc-400" />
            <flux:heading size="lg" class="mt-4">No engineers yet</flux:heading>
            <flux:text class="mt-2">Add your roster to start planning capacity.</flux:text>
            <flux:button href="/engineers/create" variant="primary" class="mt-6" wire:navigate>Add Engineer</flux:button>
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
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach($engineers as $engineer)
                <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 cursor-pointer"
                    onclick="window.location='/engineers/{{ $engineer->id }}/edit'">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
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
                @endforeach
            </tbody>
        </table>
    </flux:card>
    @endif
</div>
