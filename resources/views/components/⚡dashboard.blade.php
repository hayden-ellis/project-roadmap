<?php

use App\Models\Epic;
use App\Models\Squad;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;

new #[Layout('components.layouts.app.sidebar')] class extends Component
{
    public function with(): array
    {
        $team = Auth::user()->currentTeam;
        
        return [
            'squadCount' => $team->squads()->count(),
            'epicCount' => $team->epics()->count(),
            'squads' => $team->squads()->latest()->take(3)->get(),
            'epics' => $team->epics()->with(['status', 'squads'])->latest()->take(5)->get(),
        ];
    }
};
?>

<div>
    <div class="pt-8 pb-10">
        <h1>Welcome to Your Roadmap</h1>
        <flux:text class="mt-2 text-zinc-600 dark:text-zinc-400">Plan, track, and manage your product roadmap with your team.</flux:text>
    </div>

    @if($squadCount === 0 && $epicCount === 0)
        {{-- Onboarding State --}}
        <div class="space-y-6">
            <flux:callout icon="light-bulb">
                <flux:callout.text>
                    Let's get started! Here's how to build your roadmap:
                </flux:callout.text>
            </flux:callout>
            
            <div class="grid gap-6 sm:grid-cols-1 lg:grid-cols-3">
                {{-- Step 1: Create Squads --}}
                <flux:card class="relative">
                    <div class="absolute -top-3 -left-3 h-10 w-10 rounded-full bg-blue-500 text-white flex items-center justify-center font-bold text-lg shadow-lg">
                        1
                    </div>
                    <div class="pt-4">
                        <div class="mb-4">
                            <flux:icon.users class="h-12 w-12 text-blue-500" />
                        </div>
                        <flux:heading size="lg">Create Squads</flux:heading>
                        <flux:text class="mt-2">
                            Start by creating squads to represent your teams or workstreams. Squads help organize work across your organization.
                        </flux:text>
                        <flux:button href="/squads/create" variant="primary" class="mt-4 w-full" wire:navigate>
                            Create Your First Squad
                        </flux:button>
                    </div>
                </flux:card>

                {{-- Step 2: Create Epics --}}
                <flux:card class="relative opacity-90">
                    <div class="absolute -top-3 -left-3 h-10 w-10 rounded-full bg-zinc-400 text-white flex items-center justify-center font-bold text-lg shadow-lg">
                        2
                    </div>
                    <div class="pt-4">
                        <div class="mb-4">
                            <flux:icon.flag class="h-12 w-12 text-zinc-400" />
                        </div>
                        <flux:heading size="lg">Create Epics</flux:heading>
                        <flux:text class="mt-2">
                            Epics represent major initiatives or features. Assign them to squads and set timelines to track progress.
                        </flux:text>
                        <flux:button href="/epics/create" variant="ghost" class="mt-4 w-full" wire:navigate>
                            Create Epic
                        </flux:button>
                    </div>
                </flux:card>

                {{-- Step 3: Build Your Roadmap --}}
                <flux:card class="relative opacity-90">
                    <div class="absolute -top-3 -left-3 h-10 w-10 rounded-full bg-zinc-400 text-white flex items-center justify-center font-bold text-lg shadow-lg">
                        3
                    </div>
                    <div class="pt-4">
                        <div class="mb-4">
                            <flux:icon.calendar class="h-12 w-12 text-zinc-400" />
                        </div>
                        <flux:heading size="lg">View Roadmap</flux:heading>
                        <flux:text class="mt-2">
                            Once you've created epics, visualize your roadmap in timeline or calendar view to see the big picture.
                        </flux:text>
                        <flux:button href="/roadmap" variant="ghost" class="mt-4 w-full" wire:navigate>
                            View Roadmap
                        </flux:button>
                    </div>
                </flux:card>
            </div>
        </div>
    @else
        {{-- Active Dashboard State --}}
        <div class="space-y-6">
            {{-- Quick Stats --}}
            <div class="grid gap-6 sm:grid-cols-2">
                <flux:card>
                    <div class="flex items-center gap-4">
                        <div class="h-12 w-12 rounded-lg bg-blue-100 dark:bg-blue-900 flex items-center justify-center">
                            <flux:icon.users class="h-6 w-6 text-blue-600 dark:text-blue-400" />
                        </div>
                        <div>
                            <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">Squads</flux:text>
                            <flux:heading size="lg">{{ $squadCount }}</flux:heading>
                        </div>
                    </div>
                </flux:card>

                <flux:card>
                    <div class="flex items-center gap-4">
                        <div class="h-12 w-12 rounded-lg bg-purple-100 dark:bg-purple-900 flex items-center justify-center">
                            <flux:icon.flag class="h-6 w-6 text-purple-600 dark:text-purple-400" />
                        </div>
                        <div>
                            <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">Epics</flux:text>
                            <flux:heading size="lg">{{ $epicCount }}</flux:heading>
                        </div>
                    </div>
                </flux:card>
            </div>

            {{-- Quick Actions --}}
            <flux:card>
                <h2 class="mb-4">Quick Actions</h2>
                <div class="flex flex-wrap gap-3">
                    <flux:button href="/squads/create" icon="plus" wire:navigate>Create Squad</flux:button>
                    <flux:button href="/epics/create" icon="plus" wire:navigate>Create Epic</flux:button>
                    <flux:button href="/roadmap" icon="calendar" wire:navigate>View Roadmap</flux:button>
                </div>
            </flux:card>

            {{-- Recent Epics --}}
            @if($epics->isNotEmpty())
                <flux:card>
                    <div class="flex items-center justify-between mb-4">
                        <h2>Recent Epics</h2>
                        <flux:button href="/epics" variant="ghost" size="sm" wire:navigate>View All</flux:button>
                    </div>
                    <div class="space-y-2">
                        @foreach($epics as $epic)
                            <a href="/epics/{{ $epic->id }}/edit" wire:navigate class="block border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 hover:shadow-md transition-shadow">
                                <div class="flex items-center justify-between gap-4">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-3">
                                            <flux:heading size="base" class="truncate">{{ $epic->title }}</flux:heading>
                                            <flux:badge :color="$epic->status->slug === 'completed' ? 'green' : ($epic->status->slug === 'in-progress' ? 'blue' : ($epic->status->slug === 'blocked' ? 'red' : 'zinc'))" size="sm">
                                                {{ $epic->status->name }}
                                            </flux:badge>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-4 shrink-0">
                                        @if($epic->squads->isNotEmpty())
                                            <div class="flex items-center gap-1">
                                                @foreach($epic->squads->take(2) as $squad)
                                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-xs font-medium" style="background-color: {{ $squad->color }}20; color: {{ $squad->color }}">
                                                        <div class="h-1.5 w-1.5 rounded-full" style="background-color: {{ $squad->color }}"></div>
                                                        {{ $squad->name }}
                                                    </span>
                                                @endforeach
                                                @if($epic->squads->count() > 2)
                                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">+{{ $epic->squads->count() - 2 }}</span>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </flux:card>
            @endif
        </div>
    @endif
</div>