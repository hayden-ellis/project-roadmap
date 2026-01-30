<?php

use App\Models\QuarterPlan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app.sidebar')] class extends Component
{
    public function with(): array
    {
        $team = Auth::user()->currentTeam;

        // Get all quarter plans for this team, grouped by quarter
        $plans = QuarterPlan::where('team_id', $team->id)
            ->with(['squad'])
            ->orderBy('year', 'desc')
            ->orderBy('quarter', 'desc')
            ->orderBy('squad_id')
            ->get()
            ->map(function ($plan) use ($team) {
                // Calculate allocated points for this plan
                $allocatedPoints = (int) DB::table('epic_quarter_plans')
                    ->join('epics', 'epic_quarter_plans.epic_id', '=', 'epics.id')
                    ->where('epic_quarter_plans.squad_id', $plan->squad_id)
                    ->where('epic_quarter_plans.quarter', $plan->getQuarterString())
                    ->whereNotNull('epic_quarter_plans.story_points')
                    ->where('epics.team_id', $team->id)
                    ->sum('epic_quarter_plans.story_points');

                return [
                    'plan' => $plan,
                    'allocated_points' => $allocatedPoints,
                    'remaining_points' => $plan->available_story_points - $allocatedPoints,
                    'is_over_allocated' => $allocatedPoints > $plan->available_story_points,
                ];
            })
            ->groupBy(function ($item) {
                return $item['plan']->getQuarterString();
            });

        return [
            'plans' => $plans,
            'squads' => $team->squads()->orderBy('name')->get(),
        ];
    }
};
?>

<div>
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 pt-8 pb-10">
        <h1>Quarterly Plans</h1>
        <flux:button href="/planning/create" icon="plus" wire:navigate class="w-full sm:w-auto">Create Plan</flux:button>
    </div>

    @if($plans->isEmpty())
    <flux:card>
        <div class="text-center py-12">
            <flux:icon.calendar class="mx-auto h-12 w-12 text-zinc-400" />
            <flux:heading size="lg" class="mt-4">No plans yet</flux:heading>
            <flux:text class="mt-2">Get started by creating your first quarterly plan.</flux:text>
            <flux:button href="/planning/create" variant="primary" class="mt-6" wire:navigate>Create Plan</flux:button>
        </div>
    </flux:card>
    @else
    <div class="space-y-8">
        @foreach($plans as $quarter => $quarterPlans)
        <div>
            <h2 class="mb-4">{{ $quarter }}</h2>
            <div class="space-y-2">
                @foreach($quarterPlans as $item)
                @php
                    $plan = $item['plan'];
                    $squad = $plan->squad;
                @endphp
                <flux:card href="/planning/{{ $plan->id }}" wire:navigate class="hover:shadow-lg transition-shadow cursor-pointer p-4 {{ $item['is_over_allocated'] ? 'border-l-4 border-red-500' : '' }}" data-plan-id="{{ $plan->id }}">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="h-3 w-3 rounded-full" style="background-color: {{ $squad->color }}"></div>
                            <flux:heading size="base">{{ $squad->name }}</flux:heading>
                            @if($item['is_over_allocated'])
                            <flux:badge color="red" size="sm">Over-allocated</flux:badge>
                            @endif
                        </div>
                        <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4 text-sm">
                            <flux:text class="text-zinc-600 dark:text-zinc-400">
                                <span class="font-medium">{{ $plan->available_story_points }}</span> available
                            </flux:text>
                            <flux:text class="text-zinc-600 dark:text-zinc-400">
                                <span class="font-medium">{{ $item['allocated_points'] }}</span> allocated
                            </flux:text>
                            <flux:text class="{{ $item['remaining_points'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-zinc-600 dark:text-zinc-400' }}">
                                <span class="font-medium">{{ $item['remaining_points'] }}</span> remaining
                            </flux:text>
                        </div>
                    </div>
                </flux:card>
                @endforeach
            </div>
        </div>
        @endforeach
    </div>
    @endif
</div>
