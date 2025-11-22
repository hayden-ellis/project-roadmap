<?php

use App\Models\Squad;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app.sidebar')] class extends Component
{
    public function with(): array
    {
        return [
            'squads' => auth()->user()->currentTeam
                ->squads()
                ->withCount('epics')
                ->latest()
                ->get(),
        ];
    }
};
?>

<div>
    
        <div>
            <div class="flex items-center justify-between pt-8 pb-10">
                <h1>Squads</h1>
                <flux:button href="/squads/create" icon="plus" wire:navigate>Create Squad</flux:button>
            </div>

            @if($squads->isEmpty())
                <flux:card>
                    <div class="text-center py-12">
                        <flux:icon.users class="mx-auto h-12 w-12 text-zinc-400" />
                        <flux:heading size="lg" class="mt-4">No squads yet</flux:heading>
                        <flux:text class="mt-2">Get started by creating your first squad.</flux:text>
                        <flux:button href="/squads/create" variant="primary" class="mt-6" wire:navigate>Create Squad</flux:button>
                    </div>
                </flux:card>
            @else
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($squads as $squad)
                        <flux:card href="/squads/{{ $squad->id }}/edit" wire:navigate class="hover:shadow-lg transition-shadow cursor-pointer">
                            <div class="flex items-start gap-4">
                                <div class="h-12 w-12 rounded-lg flex-shrink-0" style="background-color: {{ $squad->color }}"></div>
                                <div class="flex-1 min-w-0">
                                    <flux:heading size="lg" class="truncate">{{ $squad->name }}</flux:heading>
                                    @if($squad->description)
                                        <flux:text class="mt-1 line-clamp-2">{{ $squad->description }}</flux:text>
                                    @endif
                                    <flux:badge class="mt-3" color="zinc" size="sm">
                                        {{ $squad->epics_count }} {{ Str::plural('epic', $squad->epics_count) }}
                                    </flux:badge>
                                </div>
                            </div>
                        </flux:card>
                    @endforeach
                </div>
            @endif
        </div>
    
</div>