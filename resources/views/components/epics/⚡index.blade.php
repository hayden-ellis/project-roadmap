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

    public function with(): array
    {
        $team = Auth::user()->currentTeam;
        $capacity = CapacityService::for($team);
        $quarter = Quarter::current();

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

        // One query tells us who is actually staffed, so the list can flag
        // epics whose status no longer matches reality.
        $staffed = $capacity->staffedEpicIds();

        $epics = $query->get()->map(function ($epic) use ($capacity, $staffed, $quarter) {
            $epic->isStaffed = $staffed->contains($epic->id);
            $epic->quarterPoints = $capacity->epicQuarterPoints($epic, $quarter);

            return $epic;
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
    <div class="flex flex-col lg:flex-row items-stretch lg:items-center gap-3 mb-6">
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
    <div class="space-y-2">
        @foreach($epics as $epic)
        <flux:card href="/epics/{{ $epic->id }}/edit" wire:navigate class="hover:shadow-lg transition-shadow cursor-pointer py-3 px-4">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-center gap-2 mb-2">
                        <flux:heading size="base" class="truncate">{{ $epic->title }}</flux:heading>

                        @if($epic->status)
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-semibold"
                              style="background-color: {{ $epic->status->color }}1f; color: {{ $epic->status->color }}">
                            <span class="size-1.5 rounded-full" style="background-color: {{ $epic->status->color }}"></span>
                            {{ $epic->status->name }}
                        </span>
                        @endif

                        @if($epic->category)
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-xs font-medium"
                              style="background-color: {{ $epic->category->color }}20; color: {{ $epic->category->color }}">
                            {{ $epic->category->name }}
                        </span>
                        @endif

                        @if($epic->priority)
                        <x-priority-icon :priority="$epic->priority" />
                        @endif

                        @if($epic->is_recurring)
                        <flux:badge color="purple" size="sm" icon="arrow-path">Recurring</flux:badge>
                        @endif

                        {{-- The whole card is already a link, so these chips
                             only display the key; opening happens from the
                             edit page or the board. --}}
                        @if($epic->jira_epic_url)
                        <x-atlassian-link :url="$epic->jira_epic_url" kind="jira" :interactive="false" />
                        @endif
                        @if($epic->jpd_idea_url)
                        <x-atlassian-link :url="$epic->jpd_idea_url" kind="idea" :interactive="false" />
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-3 text-sm text-zinc-500 dark:text-zinc-400">
                        <span class="flex items-center gap-1">
                            <flux:icon.clock variant="micro" />
                            Updated {{ $epic->updated_at->diffForHumans() }}
                        </span>
                        @if($epic->quarterPoints > 0)
                        <span class="flex items-center gap-1">
                            <flux:icon.users variant="micro" />
                            {{ $epic->quarterPoints }} pts staffed this quarter
                        </span>
                        @endif
                        @php $plan = $epic->quarterPlans->first(); @endphp
                        @if($plan?->planned_points)
                        <span class="flex items-center gap-1">
                            <flux:icon.calculator variant="micro" />
                            {{ $plan->planned_points }} pts planned
                        </span>
                        @endif
                    </div>
                </div>

                <div class="flex flex-col sm:items-end gap-2">
                    @if($epic->start_date && $epic->end_date)
                    <flux:text class="text-sm text-zinc-700 dark:text-zinc-300 whitespace-nowrap font-medium">
                        {{ $epic->start_date->format('M j') }} – {{ $epic->end_date->format('M j, Y') }}
                    </flux:text>
                    @else
                    <flux:text class="text-sm text-zinc-400 dark:text-zinc-500 whitespace-nowrap">No dates set</flux:text>
                    @endif

                    @if($epic->quarterPlans->isNotEmpty())
                    <div class="flex flex-wrap items-center gap-1 justify-end">
                        @foreach($epic->quarterPlans->pluck('squad')->filter()->unique('id') as $squad)
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-xs font-medium"
                              style="background-color: {{ $squad->color }}20; color: {{ $squad->color }}">
                            <div class="h-1.5 w-1.5 rounded-full" style="background-color: {{ $squad->color }}"></div>
                            {{ $squad->name }}
                        </span>
                        @endforeach
                    </div>
                    @endif
                </div>
            </div>
        </flux:card>
        @endforeach
    </div>
    @endif
</div>
