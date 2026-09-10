<?php

use App\Models\Epic;
use App\Models\EpicQuarterPlan;
use App\Models\Status;
use App\Support\DefaultSquad;
use App\Support\Quarter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
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
new #[Layout('components.layouts.app.header')] class extends Component
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

    /** Free text, matched against the title and the Jira link. */
    #[Url(except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->selectedSquadIds = DefaultSquad::seed($this->selectedSquadIds, 'selectedSquadIds', Auth::user(), Auth::user()->currentTeam);
    }

    public function clearFilters(): void
    {
        $this->selectedSquadIds = [];
        $this->selectedStatusIds = [];
        $this->selectedQuarter = '';
    }

    public function clearSearchAndFilters(): void
    {
        $this->search = '';
        $this->clearFilters();
    }

    /** Drops one value from a filter -- the chip's cross. */
    public function removeFilter(string $filter, string $id): void
    {
        if ($filter === 'selectedQuarter') {
            $this->selectedQuarter = '';

            return;
        }

        if (! in_array($filter, ['selectedSquadIds', 'selectedStatusIds'], true)) {
            return;
        }

        $this->{$filter} = array_values(array_filter($this->{$filter}, fn ($v) => (string) $v !== $id));
    }

    /** The shared Add epic modal just created one; re-render to show it. */
    #[On('epic-added')]
    public function refresh(): void {}

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

        // Same match as the epics list: lowercased on both sides so it is
        // case-insensitive on Postgres too, wildcards in the term escaped.
        if (($term = trim($this->search)) !== '') {
            $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
            $query->where(fn ($q) => $q
                ->whereRaw('LOWER(title) LIKE ?', [$like])
                ->orWhereRaw('LOWER(jira_epic_url) LIKE ?', [$like]));
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
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 pb-10">
        <div>
            <h1>Matrix</h1>
            <flux:text class="mt-1">Importance against urgency. Drag an epic to say where it really sits.</flux:text>
        </div>

        <flux:modal.trigger name="add-epic">
            <flux:button icon="plus" variant="primary" class="w-full sm:w-auto">Add epic</flux:button>
        </flux:modal.trigger>
    </div>

    @php
        $selectedQuarterOption = $quarterOptions->first(fn ($option) => $option->key() === $selectedQuarter);
        $hasFilters = ! empty($selectedSquadIds) || ! empty($selectedStatusIds) || $selectedQuarterOption !== null;
        $filterCount = count($selectedSquadIds) + count($selectedStatusIds) + ($selectedQuarterOption ? 1 : 0);
        $shown = $byQuadrant->flatten(1)->count();
    @endphp

    {{-- The same toolbar as the epics list: search is the wide control and
         everything that narrows the matrix sits behind one Filter button. --}}
    <div class="flex flex-col sm:flex-row sm:items-center gap-2 mb-3"
         x-on:keydown.slash.window="if (! ['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target.tagName) && ! $event.target.isContentEditable) { $event.preventDefault(); $refs.search.focus() }">
        <flux:input x-ref="search" wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search epics"
                    aria-label="Search epics" clearable kbd="/" size="sm" class="w-full sm:flex-1 sm:max-w-md" />

        <div class="flex items-center gap-2 flex-wrap">
            <flux:dropdown position="bottom" align="start">
                <flux:button size="sm" icon="funnel" icon:variant="micro">
                    Filter
                    @if($filterCount > 0)
                    <span class="inline-grid place-items-center align-middle min-w-[18px] h-[18px] px-1 rounded-full
                                 bg-zinc-900 text-white dark:bg-white dark:text-zinc-900
                                 text-[10px] font-semibold tabular-nums">{{ $filterCount }}</span>
                    @endif
                </flux:button>

                <flux:menu class="sm:min-w-[540px]">
                    <div class="grid grid-cols-1 sm:grid-cols-3 sm:divide-x divide-zinc-200 dark:divide-zinc-700">
                        <div class="sm:pr-1">
                            <flux:menu.group heading="Squad">
                                <flux:menu.checkbox.group wire:model.live="selectedSquadIds">
                                    @foreach($squads as $squad)
                                    <flux:menu.checkbox value="{{ $squad->id }}">
                                        <span class="inline-flex items-center gap-2 min-w-0">
                                            <span class="size-2 rounded-full shrink-0" style="background-color: {{ $squad->color }}"></span>
                                            <span class="truncate">{{ $squad->name }}</span>
                                        </span>
                                    </flux:menu.checkbox>
                                    @endforeach
                                </flux:menu.checkbox.group>
                            </flux:menu.group>
                        </div>
                        <div class="sm:px-1">
                            <flux:menu.group heading="Status">
                                <flux:menu.checkbox.group wire:model.live="selectedStatusIds">
                                    @foreach($statuses as $status)
                                    <flux:menu.checkbox value="{{ $status->id }}">
                                        <span class="inline-flex items-center gap-2 min-w-0">
                                            <span class="size-2 rounded-full shrink-0" style="background-color: {{ $status->color }}"></span>
                                            <span class="truncate">{{ $status->name }}</span>
                                        </span>
                                    </flux:menu.checkbox>
                                    @endforeach
                                </flux:menu.checkbox.group>
                            </flux:menu.group>
                        </div>
                        <div class="sm:pl-1">
                            {{-- One quarter at a time, so a radio rather than checkboxes. --}}
                            <flux:menu.group heading="Quarter">
                                <flux:menu.radio.group wire:model.live="selectedQuarter">
                                    <flux:menu.radio value="">All quarters</flux:menu.radio>
                                    @foreach($quarterOptions as $option)
                                    <flux:menu.radio value="{{ $option->key() }}">{{ $option->label() }}</flux:menu.radio>
                                    @endforeach
                                </flux:menu.radio.group>
                            </flux:menu.group>
                        </div>
                    </div>

                    @if($filterCount > 0)
                    <flux:menu.separator />
                    <div class="flex items-center justify-between px-2 py-1">
                        <span class="text-xs text-zinc-400 dark:text-zinc-500 tabular-nums">{{ $filterCount }} applied</span>
                        <flux:button variant="ghost" size="xs" wire:click="clearFilters">Clear all</flux:button>
                    </div>
                    @endif
                </flux:menu>
            </flux:dropdown>

            <livewire:default-squad :selected="count($selectedSquadIds) === 1 ? (int) $selectedSquadIds[0] : null" />
        </div>

        <flux:text class="sm:ml-auto text-sm whitespace-nowrap tabular-nums">{{ $shown }} {{ Str::plural('epic', $shown) }}</flux:text>
    </div>

    {{-- What is applied, in the open. Each chip removes itself. --}}
    @if($hasFilters)
    <div class="flex flex-wrap items-center gap-1.5 mb-3">
        @foreach([
            ['selectedSquadIds', 'Squad', $squads->whereIn('id', $selectedSquadIds)],
            ['selectedStatusIds', 'Status', $statuses->whereIn('id', $selectedStatusIds)],
        ] as [$filter, $label, $items])
            @foreach($items as $item)
            <span class="inline-flex items-center gap-1.5 h-6 pl-2 pr-1 rounded-md bg-zinc-100 dark:bg-zinc-800 text-xs text-zinc-600 dark:text-zinc-300"
                  wire:key="chip-{{ $filter }}-{{ $item->id }}">
                <span class="text-zinc-400 dark:text-zinc-500">{{ $label }}</span>
                @if($item->color ?? null)
                <span class="size-1.5 rounded-full" style="background-color: {{ $item->color }}"></span>
                @endif
                {{ $item->name }}
                <button type="button" wire:click="removeFilter('{{ $filter }}', '{{ $item->id }}')"
                        class="grid place-items-center size-4 rounded text-zinc-400 hover:text-zinc-900 hover:bg-zinc-200 dark:hover:text-white dark:hover:bg-zinc-700"
                        aria-label="Remove {{ $label }} {{ $item->name }}">
                    <flux:icon.x-mark variant="micro" class="size-3" />
                </button>
            </span>
            @endforeach
        @endforeach
        @if($selectedQuarterOption)
        <span class="inline-flex items-center gap-1.5 h-6 pl-2 pr-1 rounded-md bg-zinc-100 dark:bg-zinc-800 text-xs text-zinc-600 dark:text-zinc-300"
              wire:key="chip-quarter">
            <span class="text-zinc-400 dark:text-zinc-500">Quarter</span>
            {{ $selectedQuarterOption->label() }}
            <button type="button" wire:click="removeFilter('selectedQuarter', '')"
                    class="grid place-items-center size-4 rounded text-zinc-400 hover:text-zinc-900 hover:bg-zinc-200 dark:hover:text-white dark:hover:bg-zinc-700"
                    aria-label="Remove quarter {{ $selectedQuarterOption->label() }}">
                <flux:icon.x-mark variant="micro" class="size-3" />
            </button>
        </span>
        @endif
        @if($filterCount > 1)
        <flux:button variant="ghost" size="xs" wire:click="clearFilters">Clear all</flux:button>
        @endif
    </div>
    @endif

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
                    <flux:text class="text-xs">{{ ($hasFilters || trim($search) !== '') ? 'Nothing here matches.' : 'Nothing here. Drag an epic in.' }}</flux:text>
                </div>
                @endforelse
            </div>
        </section>
        @endforeach
        @endforeach
    </div>

    <livewire:quick-add-epic />
</div>
