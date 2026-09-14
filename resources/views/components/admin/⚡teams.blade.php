<?php

use App\Models\Team;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app.header')] class extends Component
{
    public function with(): array
    {
        return [
            'teams' => Team::query()
                ->with('owner')
                ->withCount(['users', 'squads', 'engineers', 'epics'])
                ->orderBy('name')
                ->get(),
        ];
    }
};
?>

<div>
    <x-admin.layout :heading="__('Teams')" :subheading="__('Every team, who owns it, and how much lives inside it')">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Team</flux:table.column>
                <flux:table.column>Owner</flux:table.column>
                <flux:table.column>Members</flux:table.column>
                <flux:table.column>Squads</flux:table.column>
                <flux:table.column>Engineers</flux:table.column>
                <flux:table.column>Epics</flux:table.column>
                <flux:table.column>Created</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($teams as $team)
                    <flux:table.row :key="$team->id" data-test="team-row-{{ $team->id }}">
                        <flux:table.cell>
                            <flux:link :href="route('admin.teams.show', $team)" wire:navigate variant="ghost" class="font-medium">{{ $team->name }}</flux:link>
                            @if ($team->personal_team)
                                <flux:badge size="sm" color="zinc" inset="top bottom" class="ms-2">Personal</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $team->owner?->name }}</flux:table.cell>
                        {{-- users_count is the pivot only; the owner is not in it. --}}
                        <flux:table.cell class="tabular-nums">{{ $team->users_count + 1 }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $team->squads_count }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $team->engineers_count }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $team->epics_count }}</flux:table.cell>
                        <flux:table.cell>{{ $team->created_at->toFormattedDateString() }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </x-admin.layout>
</div>
