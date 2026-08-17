<?php

use App\Models\Epic;
use App\Models\EpicQuarterPlan;
use App\Models\Status;
use App\Support\Quarter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The priority matrix. Importance and urgency as two axes, four quadrants,
 * and dragging as the way of saying where an epic actually sits.
 *
 * Like the board, a quadrant is stated rather than inferred -- priority
 * seeds a starting position when an epic is created, but from then on the
 * matrix records what somebody decided, not what a formula guessed.
 */
new #[Layout('components.layouts.app.sidebar')] class extends Component
{
    /** The four quadrant keys, importance/urgency. */
    private const QUADRANTS = ['high/urgent', 'high/not_urgent', 'low/urgent', 'low/not_urgent'];

    #[Url]
    public array $selectedSquadIds = [];

    #[Url]
    public array $selectedStatusIds = [];

    /** Quarter key like "2026-Q3", or '' for everything. */
    #[Url]
    public string $selectedQuarter = '';

    public string $newTitle = '';

    public string $newPriority = 'medium';

    public function clearFilters(): void
    {
        $this->selectedSquadIds = [];
        $this->selectedStatusIds = [];
        $this->selectedQuarter = '';
    }

    // -------------------------------------------------------------- quick add

    public function quickAdd(): void
    {
        $this->authorize('create', Epic::class);

        $this->validate([
            'newTitle' => 'required|string|max:255',
            'newPriority' => 'required|in:low,medium,high,critical',
        ], [
            'newTitle.required' => 'Give it a name.',
        ]);

        $team = Auth::user()->currentTeam;
        $quarter = Quarter::current();
        $status = Status::defaultFor($team);

        Epic::create([
            'team_id' => $team->id,
            'status_id' => $status?->id,
            'board_order' => $status ? ((int) $status->epics()->max('board_order')) + 1 : 0,
            'title' => $this->newTitle,
            'priority' => $this->newPriority,
            'start_date' => $quarter->start(),
            'end_date' => $quarter->end(),
        ]);

        $this->newTitle = '';
    }

    // ------------------------------------------------------------- the matrix

    /** Drag handler. The quadrant is whatever the card was dropped into. */
    public function moveEpic(int $item, int $position, string $quadrant): void
    {
        abort_unless(in_array($quadrant, self::QUADRANTS, true), 400);

        $epic = $this->teamEpic($item);
        $this->authorize('update', $epic);

        [$importance, $urgency] = explode('/', $quadrant);

        DB::transaction(function () use ($epic, $importance, $urgency, $position) {
            $epic->update(['importance' => $importance, 'urgency' => $urgency]);
            $this->resequence($epic, $position);
        });
    }

    /**
     * Rewrites matrix_order for the moved epic's quadrant.
     *
     * The dropped position counts *visible* cards, which under a filter is a
     * subset of the quadrant. The visible neighbour the card landed above
     * anchors it into the full list, so epics hidden by the filter keep
     * their relative order instead of being scrambled by every drag.
     */
    private function resequence(Epic $moved, int $position): void
    {
        $quadrant = fn () => Auth::user()->currentTeam->epics()
            ->where('importance', $moved->importance)
            ->where('urgency', $moved->urgency);

        $visible = $this->filtered($quadrant()->unfinished())->inMatrix()->pluck('id')
            ->reject(fn ($id) => $id === $moved->id)
            ->values();

        $anchor = $visible->get(max(0, $position));

        $ids = $quadrant()->inMatrix()->pluck('id')
            ->reject(fn ($id) => $id === $moved->id)
            ->values();

        $index = $anchor === null ? false : $ids->search($anchor);

        $ids->splice($index === false ? $ids->count() : $index, 0, [$moved->id]);

        foreach ($ids as $order => $id) {
            Epic::whereKey($id)->update(['matrix_order' => $order]);
        }
    }

    // ----------------------------------------------------------------- shared

    private function teamEpic(?int $epicId): Epic
    {
        abort_if($epicId === null, 404);

        return Epic::where('team_id', Auth::user()->currentTeam->id)
            ->findOr($epicId, fn () => abort(403));
    }

    private function filtered($query)
    {
        if (! empty($this->selectedSquadIds)) {
            $query->whereHas('quarterPlans', fn ($q) => $q->whereIn('squad_id', $this->selectedSquadIds));
        }

        if (! empty($this->selectedStatusIds)) {
            $query->whereIn('status_id', $this->selectedStatusIds);
        }

        if ($quarter = $this->quarterFilter()) {
            $query->forQuarter($quarter);
        }

        return $query;
    }

    /** The selected quarter, or null when unset or mangled in the URL. */
    private function quarterFilter(): ?Quarter
    {
        try {
            return $this->selectedQuarter === '' ? null : Quarter::parse($this->selectedQuarter);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function with(): array
    {
        $team = Auth::user()->currentTeam;
        $quarter = $this->quarterFilter();

        $epics = $this->filtered($team->epics()->unfinished())
            ->with(['category', 'status', 'quarterPlans.squad'])
            ->inMatrix()
            ->get();

        $epics->each(function ($epic) use ($quarter) {
            $epic->squad = $epic->quarterPlans->first()?->squad;

            $plans = $quarter
                ? $epic->quarterPlans->where('year', $quarter->year)->where('quarter', $quarter->quarter)
                : $epic->quarterPlans;

            $epic->points = (int) $plans->sum('planned_points');
        });

        // Only quarters somebody has actually planned into. Global scopes are
        // dropped because Sortable's orderBy cannot ride along on a distinct.
        $quarterOptions = EpicQuarterPlan::query()->withoutGlobalScopes()
            ->whereHas('squad', fn ($q) => $q->where('team_id', $team->id))
            ->select('year', 'quarter')->distinct()
            ->orderBy('year')->orderBy('quarter')
            ->get()
            ->map(fn ($plan) => new Quarter($plan->year, $plan->quarter));

        return [
            'byQuadrant' => $epics->groupBy(fn ($epic) => $epic->quadrant()),
            'squads' => $team->squads()->orderBy('name')->get(),
            // The matrix only shows unfinished epics, so complete statuses
            // could never match anything and stay out of the list.
            'statuses' => $team->statuses()->where('is_complete', false)->ordered()->get(),
            'quarterOptions' => $quarterOptions,
        ];
    }
};
?>

@php
    $micro = 'text-[10px] font-semibold uppercase tracking-[0.14em] text-zinc-400 dark:text-zinc-500';

    $quadrants = [
        'high' => ['urgent' => 'High · Urgent', 'not_urgent' => 'High · Not urgent'],
        'low' => ['urgent' => 'Low · Urgent', 'not_urgent' => 'Low · Not urgent'],
    ];
@endphp

<div>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6">
        <div>
            <h1>Matrix</h1>
            <flux:text class="mt-1">Importance against urgency. Drag an epic to say where it really sits.</flux:text>
        </div>
    </div>

    <div class="flex flex-col lg:flex-row items-stretch lg:items-center gap-3 mb-4">
        <flux:select multiple variant="listbox" wire:model.live="selectedSquadIds" placeholder="All Squads" class="w-full lg:w-56">
            @foreach($squads as $squad)
            <flux:select.option value="{{ $squad->id }}">{{ $squad->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select multiple variant="listbox" wire:model.live="selectedStatusIds" placeholder="All Statuses" class="w-full lg:w-56">
            @foreach($statuses as $status)
            <flux:select.option value="{{ $status->id }}">{{ $status->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select variant="listbox" wire:model.live="selectedQuarter" class="w-full lg:w-44">
            <flux:select.option value="">All Quarters</flux:select.option>
            @foreach($quarterOptions as $option)
            <flux:select.option value="{{ $option->key() }}">{{ $option->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        @if(! empty($selectedSquadIds) || ! empty($selectedStatusIds) || $selectedQuarter !== '')
        <flux:button variant="ghost" size="sm" wire:click="clearFilters" icon="x-mark" class="w-full lg:w-auto">Clear</flux:button>
        @endif
    </div>

    {{-- Capture it now; it lands where its priority implies and gets dragged
         to the truth later. --}}
    <form wire:submit="quickAdd" class="flex flex-col sm:flex-row gap-2 mb-6">
        <flux:input wire:model="newTitle" placeholder="Add an epic…" class="flex-1" />
        <flux:select variant="listbox" wire:model="newPriority" class="w-full sm:w-36">
            <flux:select.option value="low">
                <div class="flex items-center gap-2"><flux:icon.chevron-down variant="micro" class="text-blue-600 dark:text-blue-400" /> Low</div>
            </flux:select.option>
            <flux:select.option value="medium">
                <div class="flex items-center gap-2"><flux:icon.equal variant="micro" class="text-amber-600 dark:text-amber-400" /> Medium</div>
            </flux:select.option>
            <flux:select.option value="high">
                <div class="flex items-center gap-2"><flux:icon.chevron-up variant="micro" class="text-orange-600 dark:text-orange-400" /> High</div>
            </flux:select.option>
            <flux:select.option value="critical">
                <div class="flex items-center gap-2"><flux:icon.chevrons-up variant="micro" class="text-red-600 dark:text-red-400" /> Critical</div>
            </flux:select.option>
        </flux:select>
        <flux:button type="submit" icon="plus" variant="primary">Add</flux:button>
    </form>

    <div class="grid grid-cols-1 md:grid-cols-[1.25rem_minmax(0,1fr)_minmax(0,1fr)] gap-x-2 gap-y-3">
        <div class="hidden md:block"></div>
        <div class="hidden md:block {{ $micro }} text-center">Urgent</div>
        <div class="hidden md:block {{ $micro }} text-center">Not urgent</div>

        @foreach($quadrants as $importance => $row)
        <div class="hidden md:grid place-items-center">
            <span class="{{ $micro }} [writing-mode:vertical-rl] rotate-180">
                {{ $importance === 'high' ? 'High' : 'Low' }}
            </span>
        </div>

        @foreach($row as $urgency => $label)
        @php $cards = $byQuadrant["{$importance}/{$urgency}"] ?? collect(); @endphp

        <section wire:key="quadrant-{{ $importance }}-{{ $urgency }}"
                 class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50/60 dark:bg-zinc-900/50">

            <header class="flex items-center gap-2 px-3 pt-3 pb-2.5 border-b border-zinc-200 dark:border-zinc-700">
                <h2 class="{{ $micro }} flex-1">{{ $label }}</h2>
                <span class="text-[11px] tabular-nums font-medium text-zinc-400">{{ $cards->count() }}</span>
            </header>

            {{-- An empty quadrant still needs somewhere to aim at. --}}
            <div class="p-2 space-y-1.5 min-h-[13rem]"
                 x-sort.ghost="$wire.moveEpic($item, $position, '{{ $importance }}/{{ $urgency }}')"
                 x-sort:group="matrix"
                 x-sort:config="{ forceFallback: true, fallbackTolerance: 5, fallbackOnBody: true }">

                @forelse($cards as $epic)
                {{-- The left edge is the squad, same as everywhere else. --}}
                <article x-sort:item="{{ $epic->id }}" wire:key="matrix-{{ $epic->id }}"
                         class="group relative overflow-hidden flex items-center gap-2 rounded-lg border border-zinc-200 dark:border-zinc-700
                                bg-white dark:bg-zinc-900 pl-3 pr-2.5 py-2 cursor-pointer select-none
                                hover:border-zinc-300 dark:hover:border-zinc-600 transition-colors">

                    <span class="absolute inset-y-0 left-0 w-1" style="background-color: {{ $epic->squad->color ?? '#a1a1aa' }}"
                          @if($epic->squad) title="{{ $epic->squad->name }}" @endif></span>

                    <x-priority-icon :priority="$epic->priority" />

                    <a href="/epics/{{ $epic->id }}/edit" wire:navigate
                       class="flex-1 min-w-0 truncate text-[13px] font-medium leading-snug text-zinc-900 dark:text-zinc-100 hover:underline">
                        {{ $epic->title }}
                    </a>

                    @if($epic->points > 0)
                    <span class="text-[11px] tabular-nums text-zinc-400 shrink-0">{{ $epic->points }} pts</span>
                    @endif
                </article>
                @empty
                <div class="rounded-lg border border-dashed border-zinc-300 dark:border-zinc-700 py-6 px-3 text-center">
                    <flux:text class="text-xs">Nothing here. Drag an epic in.</flux:text>
                </div>
                @endforelse
            </div>
        </section>
        @endforeach
        @endforeach
    </div>
</div>
