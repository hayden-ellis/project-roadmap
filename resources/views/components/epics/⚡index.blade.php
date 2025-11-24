<?php

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
    public string $sortBy = 'created_at';

    #[Url]
    public string $sortDirection = 'desc';

    public function clearFilters(): void
    {
        $this->selectedSquadIds = [];
        $this->selectedStatusIds = [];
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
        $epicsQuery = Auth::user()->currentTeam
            ->epics()
            ->with(['status', 'squads', 'stories']);

        if (!empty($this->selectedSquadIds)) {
            $epicsQuery->whereHas('squads', function ($query) {
                $query->whereIn('squads.id', $this->selectedSquadIds);
            });
        }

        if (!empty($this->selectedStatusIds)) {
            $epicsQuery->whereIn('status_id', $this->selectedStatusIds);
        }

        // Handle sorting
        match ($this->sortBy) {
            'start_date' => $epicsQuery->orderByRaw('start_date IS NULL, start_date ' . $this->sortDirection),
            'end_date' => $epicsQuery->orderByRaw('end_date IS NULL, end_date ' . $this->sortDirection),
            'priority' => $epicsQuery->orderByRaw("CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 END " . ($this->sortDirection === 'desc' ? 'ASC' : 'DESC')),
            'title' => $epicsQuery->orderBy('title', $this->sortDirection),
            'updated_at' => $epicsQuery->orderBy('updated_at', $this->sortDirection),
            default => $epicsQuery->orderBy('created_at', $this->sortDirection),
        };

        return [
            'epics' => $epicsQuery->get(),
            'squads' => Auth::user()->currentTeam->squads()->orderBy('name')->get(),
            'statuses' => \App\Models\Status::orderBy('order')->get(),
        ];
    }
};
?>

<div>
    <div>
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 pt-8 pb-10">
            <h1>Epics</h1>
            <flux:button href="/epics/create" icon="plus" wire:navigate class="w-full sm:w-auto">Create Epic</flux:button>
        </div>

        @if(!$epics->isEmpty() || !empty($selectedSquadIds) || !empty($selectedStatusIds))
        <div class="flex flex-col gap-4 mb-6">
            <div class="flex flex-col lg:flex-row items-stretch lg:items-center gap-3">
                <flux:select multiple variant="listbox" wire:model.live="selectedSquadIds" placeholder="All Squads" class="w-full lg:w-64">
                    @foreach($squads as $squad)
                    <flux:select.option value="{{ $squad->id }}">{{ $squad->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select multiple variant="listbox" wire:model.live="selectedStatusIds" placeholder="All Statuses" class="w-full lg:w-64">
                    @foreach($statuses as $status)
                    <flux:select.option value="{{ $status->id }}">{{ $status->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="sortBy" class="w-full lg:w-64">
                    <flux:select.option value="created_at">Created {{ $sortBy === 'created_at' ? ($sortDirection === 'desc' ? '↓' : '↑') : '' }}</flux:select.option>
                    <flux:select.option value="updated_at">Updated {{ $sortBy === 'updated_at' ? ($sortDirection === 'desc' ? '↓' : '↑') : '' }}</flux:select.option>
                    <flux:select.option value="start_date">Start Date {{ $sortBy === 'start_date' ? ($sortDirection === 'desc' ? '↓' : '↑') : '' }}</flux:select.option>
                    <flux:select.option value="end_date">End Date {{ $sortBy === 'end_date' ? ($sortDirection === 'desc' ? '↓' : '↑') : '' }}</flux:select.option>
                    <flux:select.option value="title">Title {{ $sortBy === 'title' ? ($sortDirection === 'asc' ? '↑' : '↓') : '' }}</flux:select.option>
                    <flux:select.option value="priority">Priority {{ $sortBy === 'priority' ? ($sortDirection === 'desc' ? '↓' : '↑') : '' }}</flux:select.option>
                </flux:select>

                <flux:button variant="ghost" size="sm" wire:click="setSortBy('{{ $sortBy }}')" icon="{{ $sortDirection === 'asc' ? 'arrow-up' : 'arrow-down' }}" class="w-full lg:w-auto">
                    {{ $sortDirection === 'asc' ? 'Asc' : 'Desc' }}
                </flux:button>

                @if(!empty($selectedSquadIds) || !empty($selectedStatusIds))
                <flux:button variant="ghost" size="sm" wire:click="clearFilters" icon="x-mark" class="w-full lg:w-auto">
                    Clear Filters
                </flux:button>
                @endif
            </div>

            @if(!empty($selectedSquadIds) || !empty($selectedStatusIds))
            <div class="flex flex-wrap items-center gap-2">
                @foreach($selectedSquadIds as $squadId)
                    @php $squad = $squads->find($squadId); @endphp
                    @if($squad)
                    <flux:badge color="zinc" size="sm">
                        Squad: {{ $squad->name }}
                        <button wire:click="$set('selectedSquadIds', {{ json_encode(array_values(array_diff($selectedSquadIds, [$squadId]))) }})" class="ml-1 hover:text-red-600">×</button>
                    </flux:badge>
                    @endif
                @endforeach
                @foreach($selectedStatusIds as $statusId)
                    @php $status = $statuses->find($statusId); @endphp
                    @if($status)
                    <flux:badge color="zinc" size="sm">
                        Status: {{ $status->name }}
                        <button wire:click="$set('selectedStatusIds', {{ json_encode(array_values(array_diff($selectedStatusIds, [$statusId]))) }})" class="ml-1 hover:text-red-600">×</button>
                    </flux:badge>
                    @endif
                @endforeach
            </div>
            @endif
        </div>
        @endif

        @if($epics->isEmpty())
        <flux:card>
            <div class="text-center py-12">
                <flux:icon.folder class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="lg" class="mt-4">
                    @if(!empty($selectedSquadIds) || !empty($selectedStatusIds))
                    No epics match your filters
                    @else
                    No epics yet
                    @endif
                </flux:heading>
                <flux:text class="mt-2">
                    @if(!empty($selectedSquadIds) || !empty($selectedStatusIds))
                    Try adjusting your filters or clear them to see all epics.
                    @else
                    Get started by creating your first epic.
                    @endif
                </flux:text>
                @if(empty($selectedSquadIds) && empty($selectedStatusIds))
                <flux:button href="/epics/create" variant="primary" class="mt-6" wire:navigate>Create Epic</flux:button>
                @endif
            </div>
        </flux:card>
        @else
        <div class="space-y-2">
            @foreach($epics as $epic)
            <flux:card href="/epics/{{ $epic->id }}/edit" wire:navigate class="hover:shadow-lg transition-shadow cursor-pointer py-3 px-4">
                <div class="flex flex-col gap-3">
                    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-center gap-2 mb-2">
                                <flux:heading size="base" class="truncate">{{ $epic->title }}</flux:heading>
                                <flux:badge :color="$epic->status->slug === 'completed' ? 'green' : ($epic->status->slug === 'in-progress' ? 'blue' : ($epic->status->slug === 'blocked' ? 'red' : 'zinc'))" size="sm">
                                    {{ $epic->status->name }}
                                </flux:badge>
                                @if($epic->priority)
                                <flux:badge :color="$epic->priority === 'high' ? 'red' : ($epic->priority === 'medium' ? 'yellow' : 'blue')" size="sm">
                                    {{ ucfirst($epic->priority) }} Priority
                                </flux:badge>
                                @endif
                            </div>
                            <div class="flex flex-wrap items-center gap-3 text-sm text-zinc-500 dark:text-zinc-400">
                                <span class="flex items-center gap-1">
                                    <flux:icon.clock variant="micro" />
                                    Updated {{ $epic->updated_at->diffForHumans() }}
                                </span>
                                @if($epic->stories->isNotEmpty())
                                <span class="flex items-center gap-1">
                                    <flux:icon.list-bullet variant="micro" />
                                    {{ $epic->stories->count() }} {{ $epic->stories->count() === 1 ? 'story' : 'stories' }}
                                </span>
                                @endif
                            </div>
                        </div>
                        <div class="flex flex-col sm:items-end gap-2">
                            @if($epic->start_date && $epic->end_date)
                            <flux:text class="text-sm text-zinc-700 dark:text-zinc-300 whitespace-nowrap font-medium">
                                {{ $epic->start_date->format('M j') }} - {{ $epic->end_date->format('M j, Y') }}
                            </flux:text>
                            @elseif($epic->start_date)
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                                Starts {{ $epic->start_date->format('M j, Y') }}
                            </flux:text>
                            @elseif($epic->end_date)
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                                Ends {{ $epic->end_date->format('M j, Y') }}
                            </flux:text>
                            @else
                            <flux:text class="text-sm text-zinc-400 dark:text-zinc-500 whitespace-nowrap">
                                No dates set
                            </flux:text>
                            @endif
                            @if($epic->squads->isNotEmpty())
                            <div class="flex flex-wrap items-center gap-1 justify-end">
                                @foreach($epic->squads as $squad)
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-xs font-medium" style="background-color: {{ $squad->color }}20; color: {{ $squad->color }}">
                                    <div class="h-1.5 w-1.5 rounded-full" style="background-color: {{ $squad->color }}"></div>
                                    {{ $squad->name }}
                                </span>
                                @endforeach
                            </div>
                            @endif
                        </div>
                    </div>
                </div>
            </flux:card>
            @endforeach
        </div>
        @endif
    </div>

</div>