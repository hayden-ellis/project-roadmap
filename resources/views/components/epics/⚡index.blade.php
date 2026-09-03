<?php

use App\Models\Allocation;
use App\Models\Epic;
use App\Services\CapacityService;
use App\Support\Quarter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('components.layouts.app.sidebar')] class extends Component
{
    #[Url]
    public array $selectedSquadIds = [];

    #[Url]
    public array $selectedStatusIds = [];

    #[Url]
    public array $selectedCategoryIds = [];

    #[Url]
    public string $sortBy = 'created_at';

    #[Url]
    public string $sortDirection = 'desc';

    public function clearFilters(): void
    {
        $this->selectedSquadIds = [];
        $this->selectedStatusIds = [];
        $this->selectedCategoryIds = [];
    }

    public function setSortBy(string $field): void
    {
        if ($this->sortBy === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $field;
            $this->sortDirection = $field === 'title' ? 'asc' : 'desc';
        }
    }

    // ----------------------------------------------------------- inline edits

    /**
     * Renames an epic straight from its row. An empty or oversized title is
     * dropped rather than saved; the re-render puts the old title back.
     */
    public function saveTitle(int $epicId, string $title): void
    {
        $epic = $this->editable($epicId);
        $title = trim($title);

        if (Validator::make(['title' => $title], ['title' => 'required|string|max:255'])->fails()) {
            return;
        }

        if ($title !== $epic->title) {
            $epic->update(['title' => $title]);
        }
    }

    /** Moves an epic to another status, or clears it, from the row's chip. */
    public function setStatus(int $epicId, ?int $statusId): void
    {
        $epic = $this->editable($epicId);

        if ($statusId !== null) {
            // A status from another team is simply not found.
            $statusId = Auth::user()->currentTeam->statuses()->whereKey($statusId)->value('id');

            if ($statusId === null) {
                return;
            }
        }

        if ((int) $epic->status_id === (int) $statusId) {
            return;
        }

        $epic->update(['status_id' => $statusId]);

        // Whatever the old pause was about, it ended when the epic moved --
        // the same rule the edit page and the board apply.
        $epic->pauses()->open()->update(['resumed_at' => now()]);
    }

    private function editable(int $epicId): Epic
    {
        $epic = Auth::user()->currentTeam->epics()->findOrFail($epicId);

        $this->authorize('update', $epic);

        return $epic;
    }

    // ------------------------------------------------------------------ data

    public function with(): array
    {
        $team = Auth::user()->currentTeam;
        $capacity = CapacityService::for($team);
        $quarter = Quarter::current();
        $week = $capacity->currentWeek();

        $query = $team->epics()->with(['category', 'status', 'quarterPlans.squad']);

        if (! empty($this->selectedSquadIds)) {
            $query->whereHas('quarterPlans', fn ($q) => $q->whereIn('squad_id', $this->selectedSquadIds));
        }

        if (! empty($this->selectedCategoryIds)) {
            $query->whereIn('category_id', $this->selectedCategoryIds);
        }

        if (! empty($this->selectedStatusIds)) {
            $query->whereIn('status_id', $this->selectedStatusIds);
        }

        match ($this->sortBy) {
            'start_date' => $query->orderByRaw('start_date IS NULL, start_date '.$this->sortDirection),
            'end_date' => $query->orderByRaw('end_date IS NULL, end_date '.$this->sortDirection),
            'priority' => $query->orderByRaw("CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 END ".($this->sortDirection === 'desc' ? 'ASC' : 'DESC')),
            'title' => $query->orderBy('title', $this->sortDirection),
            'updated_at' => $query->orderBy('updated_at', $this->sortDirection),
            default => $query->orderBy('created_at', $this->sortDirection),
        };

        // Who is on what this week, in one query -- the same crew the board
        // shows on each card.
        $thisWeek = Allocation::inWeek($week)
            ->whereHas('engineer', fn ($q) => $q->where('team_id', $team->id))
            ->with('engineer')
            ->get()
            ->groupBy('epic_id');

        $epics = $query->get()->each(function ($epic) use ($capacity, $quarter, $thisWeek) {
            $epic->crew = ($thisWeek[$epic->id] ?? collect())->pluck('engineer')->filter()->unique('id')->values();
            $epic->staffedPoints = $capacity->epicQuarterPoints($epic, $quarter);
            $thisQuarter = $epic->quarterPlans
                ->where('year', $quarter->year)
                ->where('quarter', $quarter->quarter);

            $epic->plannedPoints = (int) $thisQuarter->sum('planned_points');

            // This quarter's squads, or whichever quarter the epic is planned
            // in when it has nothing booked right now.
            $epic->squadsShown = ($thisQuarter->isNotEmpty() ? $thisQuarter : $epic->quarterPlans)
                ->pluck('squad')->filter()->unique('id')->values();
        });

        return [
            'epics' => $epics,
            'squads' => $team->squads()->orderBy('name')->get(),
            'categories' => $team->categories()->orderBy('name')->get(),
            'statuses' => $team->statuses()->ordered()->get(),
            'quarter' => $quarter,
        ];
    }
};
?>

<div>
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 pb-10">
        <div>
            <h1>Epics</h1>
            <flux:text class="mt-1">Points shown for {{ $quarter->label() }}</flux:text>
        </div>
        <flux:button href="/epics/create" icon="plus" wire:navigate class="w-full sm:w-auto">Create Epic</flux:button>
    </div>

    @php $hasFilters = ! empty($selectedSquadIds) || ! empty($selectedStatusIds) || ! empty($selectedCategoryIds); @endphp

    @if(! $epics->isEmpty() || $hasFilters)
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

        <flux:select multiple variant="listbox" wire:model.live="selectedCategoryIds" placeholder="All Categories" class="w-full lg:w-56">
            @foreach($categories as $category)
            <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="sortBy" class="w-full lg:w-48">
            <flux:select.option value="created_at">Created</flux:select.option>
            <flux:select.option value="updated_at">Updated</flux:select.option>
            <flux:select.option value="start_date">Start Date</flux:select.option>
            <flux:select.option value="end_date">End Date</flux:select.option>
            <flux:select.option value="title">Title</flux:select.option>
            <flux:select.option value="priority">Priority</flux:select.option>
        </flux:select>

        <flux:button variant="ghost" size="sm" wire:click="setSortBy('{{ $sortBy }}')" icon="{{ $sortDirection === 'asc' ? 'arrow-up' : 'arrow-down' }}" class="w-full lg:w-auto">
            {{ $sortDirection === 'asc' ? 'Asc' : 'Desc' }}
        </flux:button>

        @if($hasFilters)
        <flux:button variant="ghost" size="sm" wire:click="clearFilters" icon="x-mark" class="w-full lg:w-auto">Clear</flux:button>
        @endif

        <flux:text class="lg:ml-auto text-sm whitespace-nowrap">{{ $epics->count() }} {{ Str::plural('epic', $epics->count()) }}</flux:text>
    </div>
    @endif

    @if($epics->isEmpty())
    <flux:card>
        <div class="text-center py-12">
            <flux:icon.folder class="mx-auto h-12 w-12 text-zinc-400" />
            <flux:heading size="lg" class="mt-4">
                {{ $hasFilters ? 'No epics match your filters' : 'No epics yet' }}
            </flux:heading>
            <flux:text class="mt-2">
                {{ $hasFilters ? 'Try adjusting your filters or clear them to see all epics.' : 'Get started by creating your first epic.' }}
            </flux:text>
            @unless($hasFilters)
            <flux:button href="/epics/create" variant="primary" class="mt-6" wire:navigate>Create Epic</flux:button>
            @endunless
        </div>
    </flux:card>
    @else
    @php
        // Priority reads twice per row: a stripe on the left edge for the
        // scan, and the existing chevron glyph for the exact rank.
        $stripe = [
            'critical' => 'bg-red-500',
            'high' => 'bg-orange-500',
            'medium' => 'bg-amber-500',
            'low' => 'bg-blue-500',
        ];
        $faces = 4;
    @endphp

    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
        <table class="w-full min-w-[860px] border-collapse text-sm">
            <thead>
                <tr class="bg-zinc-50 dark:bg-zinc-800/60 text-xs font-medium text-zinc-500 dark:text-zinc-400 border-b border-zinc-200 dark:border-zinc-700">
                    <th scope="col" class="w-10 pl-3 pr-1 py-2.5 text-left">
                        <button type="button" wire:click="setSortBy('priority')" class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-zinc-100 {{ $sortBy === 'priority' ? 'text-zinc-900 dark:text-zinc-100' : '' }}" title="Sort by priority">
                            <flux:icon.bars-arrow-down variant="micro" class="size-3.5" />
                            <span class="sr-only">Priority</span>
                            @if($sortBy === 'priority')
                            <flux:icon name="{{ $sortDirection === 'asc' ? 'arrow-up' : 'arrow-down' }}" variant="micro" class="size-3 text-accent" />
                            @endif
                        </button>
                    </th>
                    <th scope="col" class="px-3 py-2.5 text-left">
                        <button type="button" wire:click="setSortBy('title')" class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-zinc-100 {{ $sortBy === 'title' ? 'text-zinc-900 dark:text-zinc-100' : '' }}">
                            Epic
                            @if($sortBy === 'title')
                            <flux:icon name="{{ $sortDirection === 'asc' ? 'arrow-up' : 'arrow-down' }}" variant="micro" class="size-3 text-accent" />
                            @endif
                        </button>
                    </th>
                    <th scope="col" class="w-40 px-3 py-2.5 text-left">Status</th>
                    <th scope="col" class="w-40 px-3 py-2.5 text-left">Squad</th>
                    <th scope="col" class="w-36 px-3 py-2.5 text-left">Engineers</th>
                    <th scope="col" class="w-40 px-3 py-2.5 text-right">Points</th>
                    <th scope="col" class="w-10 px-2 py-2.5"><span class="sr-only">Open</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach($epics as $epic)
                <tr wire:key="epic-{{ $epic->id }}" class="group h-10 hover:bg-zinc-50/70 dark:hover:bg-zinc-800/40">
                    {{-- priority --}}
                    <td class="pl-3 pr-1">
                        <div class="flex items-center gap-2">
                            <span class="block h-5 w-[3px] rounded-full {{ $stripe[$epic->priority] ?? 'bg-zinc-300 dark:bg-zinc-600' }}"></span>
                            @if($epic->priority)
                            <x-priority-icon :priority="$epic->priority" />
                            @endif
                        </div>
                    </td>

                    {{-- title: looks like text until you click it --}}
                    <td class="px-3 max-w-0">
                        <div class="flex items-center gap-2 min-w-0">
                            <input type="text"
                                   value="{{ $epic->title }}"
                                   aria-label="Epic title"
                                   x-on:change="$wire.saveTitle({{ $epic->id }}, $event.target.value)"
                                   x-on:keydown.enter.prevent="$el.blur()"
                                   x-on:keydown.escape.prevent="$el.value = @js($epic->title); $el.blur()"
                                   class="flex-1 min-w-0 -mx-1.5 px-1.5 py-1 rounded-md border border-transparent bg-transparent
                                          text-sm font-medium text-zinc-900 dark:text-zinc-100 truncate
                                          hover:bg-zinc-100 dark:hover:bg-zinc-800 hover:border-zinc-200 dark:hover:border-zinc-700
                                          focus:bg-white dark:focus:bg-zinc-900 focus:border-accent focus:ring-2 focus:ring-accent/30 focus:outline-none" />

                            @if($epic->jira_epic_url)
                            <x-atlassian-link :url="$epic->jira_epic_url" kind="jira" />
                            @endif
                            @if($epic->jpd_idea_url)
                            <x-atlassian-link :url="$epic->jpd_idea_url" kind="idea" />
                            @endif
                            @if($epic->is_recurring)
                            <flux:tooltip content="Recurring">
                                <flux:icon.arrow-path variant="micro" class="size-3.5 shrink-0 text-zinc-400" />
                            </flux:tooltip>
                            @endif
                        </div>
                    </td>

                    {{-- status: the chip is the menu trigger --}}
                    <td class="px-3">
                        <flux:dropdown position="bottom" align="start">
                            @if($epic->status)
                            <button type="button"
                                    class="inline-flex items-center gap-1.5 h-6 pl-2 pr-1.5 rounded-md text-xs font-medium whitespace-nowrap border border-transparent hover:border-current/30 cursor-pointer"
                                    style="background-color: {{ $epic->status->color }}1f; color: {{ $epic->status->color }}">
                                <span class="size-1.5 rounded-full" style="background-color: {{ $epic->status->color }}"></span>
                                {{ $epic->status->name }}
                                <flux:icon.chevron-down variant="micro" class="size-3 opacity-60" />
                            </button>
                            @else
                            <button type="button"
                                    class="inline-flex items-center gap-1.5 h-6 pl-2 pr-1.5 rounded-md text-xs font-medium whitespace-nowrap border border-dashed border-zinc-300 dark:border-zinc-600 text-zinc-500 dark:text-zinc-400 hover:border-zinc-400 cursor-pointer">
                                No status
                                <flux:icon.chevron-down variant="micro" class="size-3 opacity-60" />
                            </button>
                            @endif

                            <flux:menu>
                                @foreach($statuses as $status)
                                <flux:menu.item wire:click="setStatus({{ $epic->id }}, {{ $status->id }})"
                                                :icon:trailing="$epic->status_id === $status->id ? 'check' : null">
                                    <span class="inline-flex items-center gap-2">
                                        <span class="size-2 rounded-full" style="background-color: {{ $status->color }}"></span>
                                        {{ $status->name }}
                                    </span>
                                </flux:menu.item>
                                @endforeach
                                @if($epic->status_id)
                                <flux:menu.separator />
                                <flux:menu.item wire:click="setStatus({{ $epic->id }}, null)" variant="danger" icon="x-mark">Clear status</flux:menu.item>
                                @endif
                            </flux:menu>
                        </flux:dropdown>
                    </td>

                    {{-- squad: one chip, never wraps; extra squads fold into +N --}}
                    <td class="px-3 max-w-0">
                        @if($epic->squadsShown->isNotEmpty())
                        @php $lead = $epic->squadsShown->first(); $rest = $epic->squadsShown->slice(1); @endphp
                        <div class="flex items-center gap-1 min-w-0 whitespace-nowrap">
                            <span class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md text-xs font-medium min-w-0"
                                  style="background-color: {{ $lead->color }}20; color: {{ $lead->color }}"
                                  title="{{ $lead->name }}">
                                <span class="size-1.5 rounded-full shrink-0" style="background-color: {{ $lead->color }}"></span>
                                <span class="truncate">{{ $lead->name }}</span>
                            </span>
                            @if($rest->isNotEmpty())
                            <flux:tooltip :content="$rest->pluck('name')->implode(', ')">
                                <span class="inline-flex items-center h-6 px-1.5 rounded-md text-xs font-medium text-zinc-500 dark:text-zinc-400 bg-zinc-100 dark:bg-zinc-800 shrink-0">+{{ $rest->count() }}</span>
                            </flux:tooltip>
                            @endif
                        </div>
                        @else
                        <span class="text-xs text-zinc-400 dark:text-zinc-500">—</span>
                        @endif
                    </td>

                    {{-- engineers booked this week --}}
                    <td class="px-3">
                        @if($epic->crew->isNotEmpty())
                        {{-- Side by side, not stacked: two initials need the
                             whole circle, and an overlap clips them. --}}
                        <div class="flex items-center gap-1">
                            @foreach($epic->crew->take($faces) as $engineer)
                            <x-engineer-avatar :engineer="$engineer" size="xs" />
                            @endforeach
                            @if($epic->crew->count() > $faces)
                            <flux:avatar circle size="xs"
                                         :tooltip="$epic->crew->skip($faces)->pluck('name')->implode(', ')">
                                +{{ $epic->crew->count() - $faces }}
                            </flux:avatar>
                            @endif
                        </div>
                        @else
                        <a href="/epics/{{ $epic->id }}/edit" wire:navigate
                           class="inline-flex items-center gap-1 h-6 px-2 rounded-md border border-dashed border-zinc-300 dark:border-zinc-600 text-xs text-zinc-500 dark:text-zinc-400
                                  opacity-0 group-hover:opacity-100 focus:opacity-100 hover:border-accent hover:text-accent">
                            <flux:icon.plus variant="micro" class="size-3" />
                            Staff
                        </a>
                        @endif
                    </td>

                    {{-- points: staffed this quarter over planned --}}
                    <td class="px-3">
                        @php
                            $planned = $epic->plannedPoints;
                            $staffed = $epic->staffedPoints;
                            $pct = $planned > 0 ? min(100, round($staffed / $planned * 100)) : 0;
                        @endphp
                        <div class="flex items-center justify-end gap-2">
                            @if($planned > 0 || $staffed > 0)
                            <span class="h-[3px] w-16 rounded-full bg-zinc-200 dark:bg-zinc-700 overflow-hidden">
                                <span class="block h-full {{ $staffed > $planned ? 'bg-orange-500' : 'bg-accent' }}" style="width: {{ $planned > 0 ? $pct : 100 }}%"></span>
                            </span>
                            @endif
                            <span class="font-mono tabular-nums text-xs text-zinc-700 dark:text-zinc-300 whitespace-nowrap">
                                {{ $staffed }}<span class="text-zinc-400 dark:text-zinc-500"> / {{ $planned }}</span>
                            </span>
                        </div>
                    </td>

                    {{-- open the full page --}}
                    <td class="px-2">
                        <flux:button href="/epics/{{ $epic->id }}/edit" wire:navigate variant="ghost" size="xs" icon="arrow-up-right"
                                     class="opacity-0 group-hover:opacity-100 focus:opacity-100" />
                        <span class="sr-only">Open {{ $epic->title }}</span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
