<?php

use App\Models\Allocation;
use App\Models\Epic;
use App\Services\CapacityService;
use App\Support\DefaultSquad;
use App\Support\Quarter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Engineer x week planning grid.
 *
 * Pick an epic as the "brush", then click or drag across cells to assign
 * people to it. Writes allocation rows at share = 1.0 -- assigned or not.
 * Two epics in one week are allowed and surface as an over-allocation rather
 * than being blocked, because that is a conversation worth having.
 */
new #[Layout('components.layouts.app.sidebar')] class extends Component
{
    #[Url]
    public string $quarter = '';

    #[Url]
    public array $squadIds = [];

    /** Epic being painted onto cells. */
    #[Url(as: 'epic')]
    public ?int $brushEpicId = null;

    public function mount(): void
    {
        $this->quarter = $this->quarter ?: Quarter::current()->key();
        $this->squadIds = DefaultSquad::seed($this->squadIds, 'squadIds', Auth::user(), Auth::user()->currentTeam);
    }

    public function setBrush(?int $epicId): void
    {
        $this->brushEpicId = $this->brushEpicId === $epicId ? null : $epicId;
    }

    /** Toggles a single engineer/week cell for the brush epic. */
    public function toggleCell(int $engineerId, string $week): void
    {
        if (! $this->brushEpicId) {
            return;
        }

        $epic = Epic::findOrFail($this->brushEpicId);
        $this->authorize('update', $epic);
        $this->assertEngineerInTeam($engineerId);

        $existing = Allocation::where('engineer_id', $engineerId)
            ->where('epic_id', $this->brushEpicId)
            ->whereDate('week_start', $week)
            ->first();

        $existing
            ? $existing->delete()
            : Allocation::create([
                'engineer_id' => $engineerId,
                'epic_id' => $this->brushEpicId,
                'week_start' => $week,
                'share' => 1.0,
            ]);
    }

    /**
     * Paints a contiguous run of weeks in one round trip -- dragging across a
     * quarter should not fire thirteen requests.
     */
    public function paintRange(int $engineerId, string $fromWeek, string $toWeek, bool $erase = false): void
    {
        if (! $this->brushEpicId) {
            return;
        }

        $epic = Epic::findOrFail($this->brushEpicId);
        $this->authorize('update', $epic);
        $this->assertEngineerInTeam($engineerId);

        $calendar = Auth::user()->currentTeam->weekCalendar();
        $weeks = collect($calendar->weeksIn(Quarter::parse($this->quarter)))
            ->map(fn ($w) => $w->toDateString());

        $start = $weeks->search($fromWeek);
        $end = $weeks->search($toWeek);

        if ($start === false || $end === false) {
            return;
        }

        $slice = $weeks->slice(min($start, $end), abs($end - $start) + 1);

        DB::transaction(function () use ($slice, $engineerId, $erase) {
            if ($erase) {
                Allocation::where('engineer_id', $engineerId)
                    ->where('epic_id', $this->brushEpicId)
                    ->whereIn('week_start', $slice)
                    ->delete();

                return;
            }

            foreach ($slice as $week) {
                Allocation::firstOrCreate(
                    ['engineer_id' => $engineerId, 'epic_id' => $this->brushEpicId, 'week_start' => $week],
                    ['share' => 1.0],
                );
            }
        });
    }

    public function clearCell(int $engineerId, string $week): void
    {
        $this->assertEngineerInTeam($engineerId);

        Allocation::where('engineer_id', $engineerId)
            ->whereDate('week_start', $week)
            ->whereHas('epic', fn ($q) => $q->where('team_id', Auth::user()->currentTeam->id))
            ->delete();
    }

    private function assertEngineerInTeam(int $engineerId): void
    {
        abort_unless(
            Auth::user()->currentTeam->engineers()->whereKey($engineerId)->exists(),
            403,
        );
    }

    public function with(): array
    {
        $team = Auth::user()->currentTeam;
        $capacity = CapacityService::for($team);
        $quarter = Quarter::parse($this->quarter);
        $weeks = $team->weekCalendar()->weeksIn($quarter);
        $currentWeek = $capacity->currentWeek()->toDateString();

        $engineers = $team->engineers()
            ->with('squad')
            ->active()
            ->when(! empty($this->squadIds), fn ($q) => $q->whereIn('squad_id', $this->squadIds))
            ->ordered()
            ->get();

        // One query for the whole grid rather than one per cell.
        $allocations = Allocation::whereIn('engineer_id', $engineers->pluck('id'))
            ->betweenWeeks($weeks[0], end($weeks))
            ->with('epic.category')
            ->get()
            ->groupBy(['engineer_id', fn ($a) => $a->week_start->toDateString()]);

        $rows = $engineers->map(fn ($engineer) => [
            'engineer' => $engineer,
            'capacity' => $capacity->quarterCapacity($engineer, $quarter),
            'allocated' => $capacity->quarterAllocatedPoints($engineer, $quarter),
            'remaining' => $capacity->remainingPoints($engineer, $quarter),
            'cells' => collect($weeks)->map(function ($week) use ($engineer, $capacity, $allocations, $currentWeek) {
                $key = $week->toDateString();

                return [
                    'week' => $key,
                    'points' => $capacity->weeklyCapacity($engineer, $week),
                    'epics' => ($allocations[$engineer->id][$key] ?? collect())->pluck('epic')->filter()->values(),
                    'over' => $capacity->isOverAllocated($engineer, $week),
                    'isCurrent' => $key === $currentWeek,
                ];
            }),
        ]);

        $epics = $team->epics()->unfinished()->with('category')->orderBy('title')->get();

        return [
            'rows' => $rows,
            'weeks' => collect($weeks)->map(fn ($w) => [
                'key' => $w->toDateString(),
                'label' => $w->format('M j'),
                'isCurrent' => $w->toDateString() === $currentWeek,
                'capacity' => $rows->sum(fn ($r) => $r['cells']->firstWhere('week', $w->toDateString())['points'] ?? 0),
            ]),
            'epics' => $epics,
            'squads' => $team->squads()->ordered()->get(),
            'quarters' => Quarter::current()->previous()->through(8),
            'brushEpic' => $this->brushEpicId ? $epics->firstWhere('id', $this->brushEpicId) : null,
        ];
    }
};
?>

<div>
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 pb-6">
        <div>
            <h1>Planning</h1>
            <flux:text class="mt-1">Pick an epic, then click or drag across weeks to staff it.</flux:text>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <flux:select wire:model.live="quarter" class="w-40">
                @foreach($quarters as $option)
                <flux:select.option value="{{ $option->key() }}">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select multiple variant="listbox" wire:model.live="squadIds" placeholder="All squads" class="w-48">
                @foreach($squads as $squad)
                <flux:select.option value="{{ $squad->id }}">{{ $squad->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <livewire:default-squad :selected="count($squadIds) === 1 ? (int) $squadIds[0] : null" />
        </div>
    </div>

    {{-- Brush palette --}}
    <flux:card class="mb-4 py-3 px-4">
        <div class="flex flex-wrap items-center gap-2">
            <flux:text class="text-xs font-medium mr-1">
                {{ $brushEpic ? 'Painting:' : 'Select an epic to assign:' }}
            </flux:text>

            @foreach($epics as $epic)
            @php $active = $brushEpic?->id === $epic->id; @endphp
            <button type="button" wire:click="setBrush({{ $epic->id }})"
                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium border transition-all
                           {{ $active
                              ? 'border-transparent ring-2 ring-offset-1 ring-zinc-900 dark:ring-white dark:ring-offset-zinc-900'
                              : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-400' }}"
                    style="{{ $active ? 'background-color: '.($epic->category->color ?? '#6B7280').'; color: white' : 'color: '.($epic->category->color ?? '#6B7280') }}">
                <span class="h-1.5 w-1.5 rounded-full" style="background-color: {{ $active ? 'white' : ($epic->category->color ?? '#6B7280') }}"></span>
                {{ $epic->title }}
            </button>
            @endforeach

            @if($brushEpic)
            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="setBrush(null)">Done</flux:button>
            @endif
        </div>
    </flux:card>

    @if($rows->isEmpty())
    <flux:card>
        <div class="text-center py-12">
            <flux:icon.users class="mx-auto h-12 w-12 text-zinc-400" />
            <flux:heading size="lg" class="mt-4">No engineers to plan</flux:heading>
            <flux:text class="mt-2">Add engineers to your roster first.</flux:text>
            <flux:button href="/engineers/create" variant="primary" class="mt-6" wire:navigate>Add Engineer</flux:button>
        </div>
    </flux:card>
    @else
    <flux:card class="p-0 overflow-x-auto"
        x-data="{
            dragging: false,
            dragEngineer: null,
            dragFrom: null,
            dragTo: null,
            erase: false,
            start(engineerId, week, hasBrushHere) {
                if (! @js((bool) $brushEpic)) return;
                this.dragging = true;
                this.dragEngineer = engineerId;
                this.dragFrom = week;
                this.dragTo = week;
                this.erase = hasBrushHere;
            },
            extend(engineerId, week) {
                if (! this.dragging || engineerId !== this.dragEngineer) return;
                this.dragTo = week;
            },
            finish() {
                if (! this.dragging) return;
                const single = this.dragFrom === this.dragTo;
                if (single) {
                    $wire.toggleCell(this.dragEngineer, this.dragFrom);
                } else {
                    $wire.paintRange(this.dragEngineer, this.dragFrom, this.dragTo, this.erase);
                }
                this.dragging = false;
                this.dragEngineer = null;
            },
            inDrag(engineerId, week) {
                if (! this.dragging || engineerId !== this.dragEngineer) return false;
                const a = this.dragFrom, b = this.dragTo;
                return (week >= a && week <= b) || (week >= b && week <= a);
            }
        }"
        @mouseup.window="finish()">

        <table class="w-full text-sm border-separate border-spacing-0" style="min-width: {{ 260 + count($weeks) * 76 }}px">
            <thead>
                <tr>
                    <th class="sticky left-0 z-20 bg-white dark:bg-zinc-900 text-left px-4 py-2 border-b border-r border-zinc-200 dark:border-zinc-700 w-64">
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Engineer</span>
                    </th>
                    @foreach($weeks as $week)
                    <th class="px-1 py-2 border-b border-zinc-200 dark:border-zinc-700 text-center
                               {{ $week['isCurrent'] ? 'bg-blue-50 dark:bg-blue-950/40' : '' }}">
                        <div class="text-xs font-medium {{ $week['isCurrent'] ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-500 dark:text-zinc-400' }}">
                            {{ $week['label'] }}
                        </div>
                        <div class="text-[10px] text-zinc-400">{{ $week['capacity'] }}p</div>
                    </th>
                    @endforeach
                </tr>
            </thead>

            <tbody>
                @foreach($rows as $row)
                <tr class="group">
                    <td class="sticky left-0 z-10 bg-white dark:bg-zinc-900 group-hover:bg-zinc-50 dark:group-hover:bg-zinc-800/50 px-4 py-2 border-b border-r border-zinc-200 dark:border-zinc-700">
                        <div class="flex items-center gap-2.5">
                            <x-engineer-avatar :engineer="$row['engineer']" size="sm" :tooltip="false" />
                            <div class="min-w-0 flex-1">
                                <a href="/engineers/{{ $row['engineer']->id }}/edit" wire:navigate
                                   class="block font-medium truncate hover:underline">{{ $row['engineer']->name }}</a>
                                <div class="text-[11px] tabular-nums {{ $row['remaining'] < 0 ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-zinc-500 dark:text-zinc-400' }}">
                                    {{ $row['allocated'] }} / {{ $row['capacity'] }} pts
                                    @if($row['remaining'] < 0)
                                        ({{ $row['remaining'] }})
                                    @endif
                                </div>
                            </div>
                        </div>
                    </td>

                    @foreach($row['cells'] as $cell)
                    <td class="p-0.5 border-b border-zinc-200 dark:border-zinc-700 align-top
                               {{ $cell['isCurrent'] ? 'bg-blue-50/50 dark:bg-blue-950/20' : '' }}">
                        <div
                            @mousedown.prevent="start({{ $row['engineer']->id }}, '{{ $cell['week'] }}', {{ $brushEpic && $cell['epics']->contains('id', $brushEpic->id) ? 'true' : 'false' }})"
                            @mouseenter="extend({{ $row['engineer']->id }}, '{{ $cell['week'] }}')"
                            :class="inDrag({{ $row['engineer']->id }}, '{{ $cell['week'] }}') ? 'ring-2 ring-zinc-900 dark:ring-white' : ''"
                            class="min-h-[44px] rounded p-1 flex flex-col gap-0.5 transition-colors select-none
                                   {{ $brushEpic ? 'cursor-pointer' : 'cursor-default' }}
                                   {{ $cell['over'] ? 'bg-red-100 dark:bg-red-950/40' : ($cell['points'] === 0 ? 'bg-zinc-100 dark:bg-zinc-800/60' : 'hover:bg-zinc-100 dark:hover:bg-zinc-800') }}"
                            title="{{ $cell['points'] }} pts available{{ $cell['over'] ? ' — over-allocated' : '' }}">

                            @forelse($cell['epics'] as $epic)
                            <span class="block text-[10px] leading-tight px-1 py-0.5 rounded truncate font-medium text-white"
                                  style="background-color: {{ $epic->category->color ?? '#6B7280' }}"
                                  title="{{ $epic->title }}">
                                {{ $epic->title }}
                            </span>
                            @empty
                            @if($cell['points'] === 0)
                            <span class="block text-[10px] text-zinc-400 text-center pt-2">—</span>
                            @endif
                            @endforelse

                            @if($cell['over'])
                            <span class="text-[9px] text-red-700 dark:text-red-300 font-semibold text-center">OVER</span>
                            @endif
                        </div>
                    </td>
                    @endforeach
                </tr>
                @endforeach
            </tbody>
        </table>
    </flux:card>

    <flux:text class="text-xs mt-3">
        Grey cells are weeks with no capacity (time off or not yet started). Red means two epics want the same person —
        allowed, so you can see the clash and decide.
    </flux:text>
    @endif
</div>
