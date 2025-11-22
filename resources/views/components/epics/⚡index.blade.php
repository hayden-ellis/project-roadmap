<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app.sidebar')] class extends Component
{
    public ?int $selectedSquadId = null;

    public function with(): array
    {
        $epicsQuery = Auth::user()->currentTeam
            ->epics()
            ->with(['status', 'squads'])
            ->latest();

        if ($this->selectedSquadId) {
            $epicsQuery->whereHas('squads', function ($query) {
                $query->where('squads.id', $this->selectedSquadId);
            });
        }

        return [
            'epics' => $epicsQuery->get(),
            'squads' => Auth::user()->currentTeam->squads()->orderBy('name')->get(),
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

        @if(!$epics->isEmpty() || $selectedSquadId)
        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 mb-4">
            <flux:select variant="listbox" wire:model.live="selectedSquadId" placeholder="All Squads" class="w-full sm:w-64">
                <flux:select.option value="">All Squads</flux:select.option>
                @foreach($squads as $squad)
                <flux:select.option value="{{ $squad->id }}">{{ $squad->name }}</flux:select.option>
                @endforeach
            </flux:select>
            @if($selectedSquadId)
                <flux:button variant="ghost" size="sm" wire:click="$set('selectedSquadId', null)" icon="x-mark" class="w-full sm:w-auto">
                    Clear
                </flux:button>
            @endif
        </div>
        @endif

        @if($epics->isEmpty())
        <flux:card>
            <div class="text-center py-12">
                <flux:icon.folder class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="lg" class="mt-4">
                    @if($selectedSquadId)
                    No epics found for selected squad
                    @else
                    No epics yet
                    @endif
                </flux:heading>
                <flux:text class="mt-2">
                    @if($selectedSquadId)
                    Try selecting a different squad or clear the filter.
                    @else
                    Get started by creating your first epic.
                    @endif
                </flux:text>
                @if(!$selectedSquadId)
                <flux:button href="/epics/create" variant="primary" class="mt-6" wire:navigate>Create Epic</flux:button>
                @endif
            </div>
        </flux:card>
        @else
        <div class="space-y-2">
            @foreach($epics as $epic)
            <flux:card href="/epics/{{ $epic->id }}/edit" wire:navigate class="hover:shadow-lg transition-shadow cursor-pointer py-3 px-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:heading size="base" class="truncate">{{ $epic->title }}</flux:heading>
                            <flux:badge :color="$epic->status->slug === 'completed' ? 'green' : ($epic->status->slug === 'in-progress' ? 'blue' : ($epic->status->slug === 'blocked' ? 'red' : 'zinc'))" size="sm">
                                {{ $epic->status->name }}
                            </flux:badge>
                        </div>
                    </div>
                    <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4">
                        @if($epic->start_date && $epic->end_date)
                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                            {{ $epic->start_date->format('M j') }} - {{ $epic->end_date->format('M j, Y') }}
                        </flux:text>
                        @endif
                        @if($epic->squads->isNotEmpty())
                        <div class="flex flex-wrap items-center gap-1">
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
            </flux:card>
            @endforeach
        </div>
        @endif
    </div>

</div>