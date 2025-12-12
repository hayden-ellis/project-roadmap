<?php

use App\Models\Epic;
use App\Models\QuarterPlan;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app.sidebar')] class extends Component
{
    public string $selectedQuarter = '';

    public array $selectedSquadIds = [];

    public array $quarterPlans = [];

    public array $editingCapacityValues = [];

    public function mount(?QuarterPlan $plan = null): void
    {
        if ($plan) {
            // Edit existing plan - load that squad into the array
            $this->authorizePlan($plan);
            $this->selectedQuarter = $plan->getQuarterString();
            $this->selectedSquadIds = [$plan->squad_id];
            $this->loadQuarterPlans();
        } else {
            // Create new plan - default to next quarter
            $now = Carbon::now();
            $currentQuarter = (int) ceil($now->month / 3);
            $currentYear = $now->year;

            if ($currentQuarter === 4) {
                $this->selectedQuarter = 'Q1-'.($currentYear + 1);
            } else {
                $this->selectedQuarter = 'Q'.($currentQuarter + 1)."-{$currentYear}";
            }

            // Ensure selectedQuarter is in availableQuarters
            $availableQuarters = $this->getAvailableQuarters();
            if (! in_array($this->selectedQuarter, $availableQuarters, true)) {
                $this->selectedQuarter = $availableQuarters[0] ?? 'Q1-2026';
            }
        }
    }

    public function loadQuarterPlans(): void
    {
        if (empty($this->selectedSquadIds) || ! $this->selectedQuarter) {
            $this->quarterPlans = [];
            $this->editingCapacityValues = [];
            return;
        }

        $team = Auth::user()->currentTeam;
        $parsed = QuarterPlan::parseQuarter($this->selectedQuarter);

        $plans = QuarterPlan::where('team_id', $team->id)
            ->whereIn('squad_id', $this->selectedSquadIds)
            ->where('year', $parsed['year'])
            ->where('quarter', $parsed['quarter'])
            ->get()
            ->keyBy('squad_id');

        $this->quarterPlans = [];
        $this->editingCapacityValues = [];

        foreach ($this->selectedSquadIds as $squadId) {
            $plan = $plans->get($squadId);
            $this->quarterPlans[$squadId] = $plan;
            $this->editingCapacityValues[$squadId] = $plan?->available_story_points ?? 0;
        }
    }

    public function getAvailableQuarters(): array
    {
        $quarters = [];
        $now = Carbon::now();
        $currentYear = $now->year;
        $currentQuarter = (int) ceil($now->month / 3);

        // Calculate next quarter to start from
        $nextQuarter = $currentQuarter === 4 ? 1 : $currentQuarter + 1;
        $nextYear = $currentQuarter === 4 ? $currentYear + 1 : $currentYear;

        // Generate quarters from next quarter to 2 years ahead
        for ($year = $nextYear; $year <= $currentYear + 2; $year++) {
            $startQuarter = $year === $nextYear ? $nextQuarter : 1;
            for ($q = $startQuarter; $q <= 4; $q++) {
                $quarters[] = "Q{$q}-{$year}";
            }
        }

        return $quarters;
    }

    public function updatedSelectedQuarter(): void
    {
        // Reload quarter plans for the new quarter
        $this->loadQuarterPlans();
        $this->saveAllPlans();
    }

    public function updatedSelectedSquadIds(): void
    {
        // Load quarter plans for selected squads
        $this->loadQuarterPlans();
        $this->saveAllPlans();
    }

    public function saveAllPlans(): void
    {
        if (! $this->selectedQuarter || empty($this->selectedSquadIds)) {
            return;
        }

        $team = Auth::user()->currentTeam;
        $parsed = QuarterPlan::parseQuarter($this->selectedQuarter);

        foreach ($this->selectedSquadIds as $squadId) {
            $this->quarterPlans[$squadId] = QuarterPlan::updateOrCreate(
                [
                    'team_id' => $team->id,
                    'squad_id' => $squadId,
                    'year' => $parsed['year'],
                    'quarter' => $parsed['quarter'],
                ],
                [
                    'available_story_points' => $this->editingCapacityValues[$squadId] ?? 0,
                ]
            );
        }
    }

    public function saveCapacityForSquad(int $squadId): void
    {
        if (! in_array($squadId, $this->selectedSquadIds)) {
            return;
        }

        $team = Auth::user()->currentTeam;
        $parsed = QuarterPlan::parseQuarter($this->selectedQuarter);

        $this->quarterPlans[$squadId] = QuarterPlan::updateOrCreate(
            [
                'team_id' => $team->id,
                'squad_id' => $squadId,
                'year' => $parsed['year'],
                'quarter' => $parsed['quarter'],
            ],
            [
                'available_story_points' => $this->editingCapacityValues[$squadId] ?? 0,
            ]
        );
    }

    public function getTotalAllocatedPointsForSquad(int $squadId): int
    {
        $team = Auth::user()->currentTeam;

        return (int) DB::table('epic_squad')
            ->join('epics', 'epic_squad.epic_id', '=', 'epics.id')
            ->where('epic_squad.squad_id', $squadId)
            ->where('epic_squad.planned_quarter', $this->selectedQuarter)
            ->whereNotNull('epic_squad.story_points')
            ->where('epics.team_id', $team->id)
            ->sum('epic_squad.story_points');
    }

    public function getPlannedEpicsForSquad(int $squadId): \Illuminate\Support\Collection
    {
        $team = Auth::user()->currentTeam;
        $selectedQuarter = $this->selectedQuarter;

        return $team->epics()
            ->with(['status', 'squads'])
            ->whereHas('squads', function ($q) use ($squadId, $selectedQuarter) {
                $q->where('squads.id', $squadId)
                    ->where('epic_squad.planned_quarter', $selectedQuarter);
            })
            ->get()
            ->map(function ($epic) use ($squadId) {
                $plannedPivot = $epic->squads->firstWhere('id', $squadId)?->pivot;
                $epic->planned_story_points = $plannedPivot->story_points ?? null;

                return $epic;
            });
    }

    public function getSharedPlannedEpics(): \Illuminate\Support\Collection
    {
        if (count($this->selectedSquadIds) < 2) {
            return collect();
        }

        $team = Auth::user()->currentTeam;
        $selectedQuarter = $this->selectedQuarter;
        $selectedSquadIds = $this->selectedSquadIds;

        // Get epics planned for 2+ of the selected squads
        return $team->epics()
            ->with(['status', 'squads'])
            ->get()
            ->filter(function ($epic) use ($selectedSquadIds, $selectedQuarter) {
                $plannedCount = $epic->squads
                    ->whereIn('id', $selectedSquadIds)
                    ->filter(fn($squad) => $squad->pivot->planned_quarter === $selectedQuarter)
                    ->count();
                return $plannedCount >= 2;
            })
            ->map(function ($epic) use ($selectedSquadIds, $selectedQuarter) {
                $squadStoryPoints = [];
                foreach ($epic->squads->whereIn('id', $selectedSquadIds) as $squad) {
                    if ($squad->pivot->planned_quarter === $selectedQuarter) {
                        $squadStoryPoints[$squad->id] = $squad->pivot->story_points;
                    }
                }
                $epic->setAttribute('squad_story_points', $squadStoryPoints);
                return $epic;
            });
    }

    public function getUniqueEpicsForSquad(int $squadId): \Illuminate\Support\Collection
    {
        if (count($this->selectedSquadIds) < 2) {
            return $this->getPlannedEpicsForSquad($squadId);
        }

        $team = Auth::user()->currentTeam;
        $selectedQuarter = $this->selectedQuarter;
        $otherSquadIds = array_values(array_diff($this->selectedSquadIds, [$squadId]));

        return $team->epics()
            ->with(['status', 'squads'])
            ->whereHas('squads', function ($q) use ($squadId, $selectedQuarter) {
                $q->where('squads.id', $squadId)
                    ->where('epic_squad.planned_quarter', $selectedQuarter);
            })
            ->get()
            ->filter(function ($epic) use ($otherSquadIds, $selectedQuarter) {
                // Filter out epics planned for any other selected squad
                foreach ($epic->squads->whereIn('id', $otherSquadIds) as $squad) {
                    if ($squad->pivot->planned_quarter === $selectedQuarter) {
                        return false;
                    }
                }
                return true;
            })
            ->map(function ($epic) use ($squadId) {
                $plannedPivot = $epic->squads->firstWhere('id', $squadId)?->pivot;
                $epic->planned_story_points = $plannedPivot->story_points ?? null;

                return $epic;
            });
    }

    public function getAvailableEpics(): \Illuminate\Support\Collection
    {
        if (empty($this->selectedSquadIds)) {
            return collect();
        }

        $team = Auth::user()->currentTeam;
        $selectedQuarter = $this->selectedQuarter;
        $selectedSquadIds = $this->selectedSquadIds;
        $quarterDates = QuarterPlan::getQuarterDates($selectedQuarter);

        // Get epics assigned to any selected squad
        return $team->epics()
            ->with(['status', 'squads'])
            ->whereHas('squads', function ($q) use ($selectedSquadIds) {
                $q->whereIn('squads.id', $selectedSquadIds);
            })
            ->where(function ($query) use ($quarterDates) {
                $query->where(function ($q) use ($quarterDates) {
                    $q->whereNotNull('start_date')
                        ->whereNotNull('end_date')
                        ->where('start_date', '<=', $quarterDates['end'])
                        ->where('end_date', '>=', $quarterDates['start']);
                })
                ->orWhere(function ($q) {
                    $q->whereNull('start_date')
                        ->orWhereNull('end_date');
                });
            })
            ->get()
            ->map(function ($epic) use ($selectedSquadIds, $selectedQuarter) {
                // Track which selected squads the epic is assigned to
                $epic->assigned_squad_ids = $epic->squads->whereIn('id', $selectedSquadIds)->pluck('id')->toArray();

                // Track which squads already have it planned
                $epic->planned_for_squad_ids = $epic->squads
                    ->whereIn('id', $selectedSquadIds)
                    ->filter(fn($squad) => $squad->pivot->planned_quarter === $selectedQuarter)
                    ->pluck('id')
                    ->toArray();

                // Track which squads can still add it
                $epic->available_for_squad_ids = array_values(array_diff($epic->assigned_squad_ids, $epic->planned_for_squad_ids));

                return $epic;
            })
            ->filter(function ($epic) {
                // Only show if there's at least one squad that can add it
                return ! empty($epic->available_for_squad_ids);
            });
    }

    public function addEpicToPlan(int $epicId, array $targetSquadIds = []): void
    {
        if (empty($targetSquadIds)) {
            return;
        }

        $epic = Epic::findOrFail($epicId);
        $this->authorizeEpic($epic);

        foreach ($targetSquadIds as $squadId) {
            if (! in_array($squadId, $this->selectedSquadIds)) {
                continue;
            }

            $existingPivot = $epic->squads()->where('squads.id', $squadId)->first()?->pivot;
            $existingPoints = $existingPivot->story_points ?? null;

            if ($epic->squads()->where('squads.id', $squadId)->exists()) {
                $epic->squads()->updateExistingPivot($squadId, [
                    'planned_quarter' => $this->selectedQuarter,
                    'story_points' => $existingPoints,
                ]);
            } else {
                $epic->squads()->attach($squadId, [
                    'planned_quarter' => $this->selectedQuarter,
                    'story_points' => $existingPoints,
                ]);
            }
        }
    }

    public function addEpicToAllSquads(int $epicId): void
    {
        $epic = Epic::findOrFail($epicId);
        $assignedSquadIds = $epic->squads->pluck('id')->toArray();
        $targetSquadIds = array_intersect($this->selectedSquadIds, $assignedSquadIds);
        $this->addEpicToPlan($epicId, $targetSquadIds);
    }

    public function updateEpicStoryPoints(int $epicId, int $squadId, ?int $storyPoints): void
    {
        if (! in_array($squadId, $this->selectedSquadIds)) {
            return;
        }

        $epic = Epic::findOrFail($epicId);
        $this->authorizeEpic($epic);

        $epic->squads()->updateExistingPivot($squadId, [
            'story_points' => $storyPoints,
            'planned_quarter' => $this->selectedQuarter,
        ]);
    }

    public function removeEpicFromPlan(int $epicId, int $squadId): void
    {
        if (! in_array($squadId, $this->selectedSquadIds)) {
            return;
        }

        $epic = Epic::findOrFail($epicId);
        $this->authorizeEpic($epic);

        $epic->squads()->updateExistingPivot($squadId, [
            'planned_quarter' => null,
        ]);
    }

    private function authorizeEpic(Epic $epic): void
    {
        if ($epic->team_id !== Auth::user()->currentTeam->id) {
            abort(403);
        }
    }

    private function authorizePlan(QuarterPlan $plan): void
    {
        if ($plan->team_id !== Auth::user()->currentTeam->id) {
            abort(403);
        }
    }

    public function with(): array
    {
        $squads = Auth::user()->currentTeam->squads()->orderBy('name')->get();
        $selectedSquads = $squads->whereIn('id', $this->selectedSquadIds);
        $isMultiSquadView = count($this->selectedSquadIds) > 1;

        // Build capacity and epic data per squad
        $squadData = [];
        foreach ($this->selectedSquadIds as $squadId) {
            $squad = $squads->find($squadId);
            if (! $squad) {
                continue;
            }

            $capacity = $this->editingCapacityValues[$squadId] ?? 0;
            $allocated = $this->getTotalAllocatedPointsForSquad($squadId);

            $squadData[$squadId] = [
                'squad' => $squad,
                'capacity' => $capacity,
                'allocated' => $allocated,
                'remaining' => $capacity - $allocated,
                'is_over_allocated' => $allocated > $capacity,
                'percentage' => $capacity > 0 ? min(($allocated / $capacity) * 100, 100) : 0,
                'planned_epics' => $isMultiSquadView
                    ? $this->getUniqueEpicsForSquad($squadId)
                    : $this->getPlannedEpicsForSquad($squadId),
            ];
        }

        return [
            'squads' => $squads,
            'selectedSquads' => $selectedSquads,
            'selectedSquadIds' => $this->selectedSquadIds,
            'squadData' => $squadData,
            'sharedEpics' => $this->getSharedPlannedEpics(),
            'availableEpics' => $this->getAvailableEpics(),
            'availableQuarters' => $this->getAvailableQuarters(),
            'isMultiSquadView' => $isMultiSquadView,
        ];
    }
};
?>

<div>
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 pb-8">
        <div>
            <flux:button href="/planning" variant="ghost" icon="arrow-left" wire:navigate class="mb-3">Back to Plans</flux:button>
            <h1>{{ count($selectedSquadIds) > 0 ? 'Edit Plan' : 'Create Plan' }}</h1>
            <flux:text class="text-zinc-600 dark:text-zinc-400 mt-2">
                @if($isMultiSquadView)
                    Planning for multiple squads - shared epics shown together
                @else
                    Configure capacity and allocate epics for your squad
                @endif
            </flux:text>
        </div>
    </div>

    <!-- Quarter and Squad Selectors -->
    <div class="mb-6 flex flex-col sm:flex-row gap-8">
        <div class="w-36">
            <flux:field class="flex-1">
                <flux:label>Select Quarter</flux:label>
                <flux:select variant="listbox" wire:model.live.debounce.500ms="selectedQuarter" placeholder="Select quarter...">
                    @foreach($availableQuarters as $quarter)
                    <flux:select.option value="{{ $quarter }}">{{ $quarter }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>
        </div>

        @if($selectedQuarter)
        <div class="w-64">
            <flux:field class="flex-1">
                <flux:label>Select Squads</flux:label>
                <flux:select variant="listbox" multiple wire:model.live.debounce.500ms="selectedSquadIds" placeholder="Select squads...">
                    @foreach($squads as $squad)
                    <flux:select.option value="{{ $squad->id }}">
                        <div class="flex items-center gap-2">
                            <div class="h-2 w-2 rounded-full" style="background-color: {{ $squad->color }}"></div>
                            {{ $squad->name }}
                        </div>
                    </flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>
        </div>
        @endif
    </div>

    @if(!empty($selectedSquadIds))
        @if($isMultiSquadView)
            {{-- Multi-Squad View --}}

            {{-- Capacity Cards Row --}}
            <div class="grid gap-4 mb-6" style="grid-template-columns: repeat({{ count($squadData) }}, minmax(0, 1fr));">
                @foreach($squadData as $squadId => $data)
                <flux:card class="p-4">
                    <div class="flex items-center gap-2 mb-3">
                        <div class="h-3 w-3 rounded-full" style="background-color: {{ $data['squad']->color }}"></div>
                        <flux:text class="font-medium">{{ $data['squad']->name }}</flux:text>
                    </div>

                    <div class="flex items-center gap-1.5 mb-2">
                        <div class="w-20">
                            <flux:input
                                type="number"
                                wire:model.blur="editingCapacityValues.{{ $squadId }}"
                                wire:change="saveCapacityForSquad({{ $squadId }})"
                                min="0"
                                placeholder="0"
                            />
                        </div>
                        <flux:text class="text-sm text-zinc-500">pts capacity</flux:text>
                    </div>

                    <div class="relative h-4 bg-zinc-100 dark:bg-zinc-800 rounded overflow-hidden mb-1">
                        <div
                            class="h-full transition-all {{ $data['is_over_allocated'] ? 'bg-red-500' : 'bg-green-500' }}"
                            style="width: {{ $data['is_over_allocated'] ? 100 : $data['percentage'] }}%"
                        ></div>
                        <div class="absolute inset-0 flex items-center justify-center">
                            <flux:text class="text-xs font-semibold">
                                {{ $data['allocated'] }} / {{ $data['capacity'] }}
                            </flux:text>
                        </div>
                    </div>

                    <flux:text class="text-sm {{ $data['is_over_allocated'] ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                        @if($data['is_over_allocated'])
                            +{{ abs($data['remaining']) }} over
                        @elseif($data['remaining'] === 0)
                            Fully allocated
                        @else
                            {{ $data['remaining'] }} left
                        @endif
                    </flux:text>
                </flux:card>
                @endforeach
            </div>

            {{-- Shared Epics Section --}}
            @if($sharedEpics->isNotEmpty())
            <div class="mb-6">
                <div class="flex items-center gap-3 mb-3">
                    <h3 class="text-sm text-purple-600 dark:text-purple-400">Shared Across Squads</h3>
                    <div class="flex-1 h-px bg-purple-200 dark:bg-purple-900"></div>
                    <flux:badge color="purple" size="sm">{{ $sharedEpics->count() }}</flux:badge>
                </div>

                <div class="space-y-1">
                    @foreach($sharedEpics as $epic)
                    <div class="flex items-center gap-3 px-3 py-2 bg-purple-50/50 dark:bg-purple-950/20 border-l-4 border-purple-500 rounded-r">
                        <div class="flex items-center gap-2 min-w-0 flex-1">
                            <flux:text class="text-sm font-medium truncate">{{ $epic->title }}</flux:text>
                            <flux:badge :color="$epic->status->slug === 'completed' ? 'green' : ($epic->status->slug === 'in-progress' ? 'blue' : ($epic->status->slug === 'blocked' ? 'red' : 'zinc'))" size="sm" class="shrink-0">
                                {{ $epic->status->name }}
                            </flux:badge>
                        </div>
                        <div class="flex items-center gap-3 shrink-0">
                            @foreach($squadData as $squadId => $data)
                                @if(isset($epic->squad_story_points[$squadId]))
                                <div class="flex items-center gap-1.5 px-2 py-1 bg-white dark:bg-zinc-800 rounded border border-zinc-200 dark:border-zinc-700">
                                    <div class="h-2 w-2 rounded-full shrink-0" style="background-color: {{ $data['squad']->color }}"></div>
                                    <span class="text-xs text-zinc-600 dark:text-zinc-400 max-w-16 truncate">{{ $data['squad']->name }}</span>
                                    <div class="w-18">
                                        <flux:input
                                            type="number"
                                            wire:change.debounce.500ms="updateEpicStoryPoints({{ $epic->id }}, {{ $squadId }}, $event.target.value ? parseInt($event.target.value) : null)"
                                            value="{{ $epic->squad_story_points[$squadId] }}"
                                            min="0"
                                            placeholder="pts"
                                        />
                                    </div>
                                    <flux:button
                                        variant="ghost"
                                        size="xs"
                                        wire:click="removeEpicFromPlan({{ $epic->id }}, {{ $squadId }})"
                                        icon="x-mark"
                                    />
                                </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Squad-Specific Columns --}}
            <div class="grid gap-4 mb-6" style="grid-template-columns: repeat({{ count($squadData) }}, minmax(0, 1fr));">
                @foreach($squadData as $squadId => $data)
                <div>
                    <div class="flex items-center gap-2 mb-3">
                        <div class="h-2 w-2 rounded-full" style="background-color: {{ $data['squad']->color }}"></div>
                        <h3 class="text-sm font-medium">{{ $data['squad']->name }} Only</h3>
                        <flux:badge color="zinc" size="sm">{{ $data['planned_epics']->count() }}</flux:badge>
                    </div>

                    <div class="space-y-1">
                        @forelse($data['planned_epics'] as $epic)
                        <div class="flex items-center gap-2 px-3 py-2 bg-zinc-50 dark:bg-zinc-800/50 border-l-4 rounded-r" style="border-color: {{ $data['squad']->color }}">
                            <flux:text class="text-sm truncate flex-1">{{ $epic->title }}</flux:text>
                            <div class="w-14 shrink-0">
                                <flux:input
                                    type="number"
                                    wire:change.debounce.500ms="updateEpicStoryPoints({{ $epic->id }}, {{ $squadId }}, $event.target.value ? parseInt($event.target.value) : null)"
                                    value="{{ $epic->planned_story_points }}"
                                    min="0"
                                    placeholder="pts"
                                />
                            </div>
                            <flux:button
                                variant="ghost"
                                size="xs"
                                wire:click="removeEpicFromPlan({{ $epic->id }}, {{ $squadId }})"
                                icon="x-mark"
                            />
                        </div>
                        @empty
                        <div class="text-center py-3 border border-dashed border-zinc-200 dark:border-zinc-700 rounded text-xs text-zinc-400">
                            No unique epics
                        </div>
                        @endforelse
                    </div>
                </div>
                @endforeach
            </div>

            {{-- Available Epics --}}
            <div class="flex items-center gap-3 mb-3">
                <h3 class="text-sm text-zinc-500 dark:text-zinc-400">Available to Add</h3>
                <div class="flex-1 h-px bg-zinc-200 dark:bg-zinc-800"></div>
                <flux:badge color="zinc" size="sm">{{ $availableEpics->count() }}</flux:badge>
            </div>

            <div class="space-y-1">
                @forelse($availableEpics as $epic)
                <div class="flex items-center gap-3 px-3 py-2 bg-zinc-50 dark:bg-zinc-800/30 rounded hover:bg-zinc-100 dark:hover:bg-zinc-800/50 transition-colors">
                    <div class="flex items-center gap-2 min-w-0 flex-1">
                        <flux:text class="text-sm truncate">{{ $epic->title }}</flux:text>
                        <div class="flex items-center gap-1 shrink-0">
                            @foreach($epic->squads->whereIn('id', array_keys($squadData)) as $squad)
                            <div class="flex items-center gap-0.5">
                                <div class="h-2 w-2 rounded-full" style="background-color: {{ $squad->color }}"></div>
                                @if(in_array($squad->id, $epic->planned_for_squad_ids))
                                    <flux:icon.check variant="micro" class="text-green-600 h-3 w-3" />
                                @endif
                            </div>
                            @endforeach
                        </div>
                    </div>

                    <flux:dropdown>
                        <flux:button variant="primary" size="xs" icon="plus">Add</flux:button>
                        <flux:menu>
                            @foreach($epic->available_for_squad_ids as $availableSquadId)
                                @php $squad = $squadData[$availableSquadId]['squad']; @endphp
                                <flux:menu.item wire:click="addEpicToPlan({{ $epic->id }}, [{{ $availableSquadId }}])">
                                    <div class="flex items-center gap-2">
                                        <div class="h-2 w-2 rounded-full" style="background-color: {{ $squad->color }}"></div>
                                        {{ $squad->name }}
                                    </div>
                                </flux:menu.item>
                            @endforeach
                            @if(count($epic->available_for_squad_ids) > 1)
                                <flux:menu.separator />
                                <flux:menu.item wire:click="addEpicToPlan({{ $epic->id }}, {{ json_encode(array_values($epic->available_for_squad_ids)) }})">
                                    <div class="flex items-center gap-2">
                                        <flux:icon.squares-plus variant="mini" />
                                        Add to All
                                    </div>
                                </flux:menu.item>
                            @endif
                        </flux:menu>
                    </flux:dropdown>
                </div>
                @empty
                <div class="text-center py-4 border border-dashed border-zinc-200 dark:border-zinc-700 rounded text-xs text-zinc-400">
                    No available epics for {{ $selectedQuarter }}
                </div>
                @endforelse
            </div>

        @else
            {{-- Single Squad View (original design) --}}
            @php
                $singleSquadId = $selectedSquadIds[0] ?? null;
                $data = $squadData[$singleSquadId] ?? null;
            @endphp

            @if($data)
            <flux:card class="mb-6">
                @php
                    $percentage = $data['percentage'];
                    $isOverAllocated = $data['is_over_allocated'];
                    $remaining = $data['remaining'];
                    $capacity = $data['capacity'];
                    $totalAllocated = $data['allocated'];
                @endphp

                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-3 shrink-0">
                        <div class="flex items-center gap-2">
                            <div class="h-3 w-3 rounded-full" style="background-color: {{ $data['squad']->color }}"></div>
                            <flux:text class="font-medium">{{ $data['squad']->name }}</flux:text>
                        </div>
                        <div class="h-4 w-px bg-zinc-300 dark:bg-zinc-700"></div>
                        <div class="flex items-center gap-1.5 w-28">
                            <flux:input
                                type="number"
                                wire:model.blur="editingCapacityValues.{{ $singleSquadId }}"
                                wire:change="saveCapacityForSquad({{ $singleSquadId }})"
                                min="0"
                                placeholder="0"
                            />
                            <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">pts</flux:text>
                        </div>
                    </div>

                    <div class="relative h-6 flex-1 bg-zinc-100 dark:bg-zinc-800 rounded-md overflow-hidden">
                        <div
                            class="h-full transition-all duration-300 {{ $isOverAllocated ? 'bg-red-500 dark:bg-red-600' : 'bg-green-500 dark:bg-green-600' }}"
                            style="width: {{ $isOverAllocated ? '100' : $percentage }}%"
                        ></div>
                        <div class="absolute inset-0 flex items-center justify-center">
                            <flux:text class="text-xs font-semibold {{ $percentage > 50 ? 'text-white' : 'text-zinc-900 dark:text-zinc-100' }}">
                                {{ $totalAllocated }} / {{ $capacity }}
                                @if($capacity > 0)
                                ({{ number_format($percentage, 0) }}%)
                                @endif
                            </flux:text>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        <flux:text class="text-sm whitespace-nowrap">
                            @if($isOverAllocated)
                                <span class="text-red-600 dark:text-red-400 font-medium">+{{ abs($remaining) }}</span>
                            @elseif($remaining === 0)
                                <span class="text-green-600 dark:text-green-400 font-medium">✓</span>
                            @else
                                <span class="text-green-600 dark:text-green-400 font-medium">{{ $remaining }} left</span>
                            @endif
                        </flux:text>
                        @if($isOverAllocated)
                        <flux:badge color="red" size="sm">Over</flux:badge>
                        @endif
                    </div>
                </div>
            </flux:card>

            <div class="mb-6">
                <h2 class="mb-6">Plan Epics</h2>

                <div class="mb-8">
                    <div class="flex items-center gap-3 mb-5">
                        <h3 class="text-green-600 dark:text-green-400">In Plan</h3>
                        <div class="flex-1 h-px bg-green-200 dark:bg-green-900"></div>
                        <flux:badge color="green" size="sm">{{ $data['planned_epics']->count() }} epic{{ $data['planned_epics']->count() !== 1 ? 's' : '' }}</flux:badge>
                    </div>

                    @if($data['planned_epics']->isEmpty())
                    <flux:card class="border-2 border-dashed border-zinc-300 dark:border-zinc-700">
                        <div class="text-center py-8">
                            <flux:text class="text-zinc-500 dark:text-zinc-400">No epics in plan yet. Add epics from below.</flux:text>
                        </div>
                    </flux:card>
                    @else
                    <div class="space-y-2">
                        @foreach($data['planned_epics'] as $epic)
                        <flux:card class="p-4 border-l-4 border-green-500 bg-green-50/50 dark:bg-green-950/20">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <flux:heading size="base">{{ $epic->title }}</flux:heading>
                                        <flux:badge :color="$epic->status->slug === 'completed' ? 'green' : ($epic->status->slug === 'in-progress' ? 'blue' : ($epic->status->slug === 'blocked' ? 'red' : 'zinc'))" size="sm">
                                            {{ $epic->status->name }}
                                        </flux:badge>
                                    </div>
                                    @if($epic->description)
                                    <flux:text class="text-xs text-zinc-500 dark:text-zinc-400 truncate mt-1">{{ $epic->description }}</flux:text>
                                    @endif
                                    @php
                                        $otherSquads = $epic->squads->where('id', '!=', $singleSquadId);
                                    @endphp
                                    @if($otherSquads->isNotEmpty())
                                    <div class="flex items-center gap-1.5 mt-2 flex-wrap">
                                        <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">Also:</flux:text>
                                        @foreach($otherSquads as $squad)
                                        <div class="flex items-center gap-1 px-1.5 py-0.5 rounded bg-zinc-100 dark:bg-zinc-800">
                                            <div class="h-1.5 w-1.5 rounded-full" style="background-color: {{ $squad->color }}"></div>
                                            <flux:text class="text-xs text-zinc-600 dark:text-zinc-400">{{ $squad->name }}</flux:text>
                                            @if($squad->pivot->story_points)
                                            <flux:text class="text-xs font-medium text-zinc-700 dark:text-zinc-300">({{ $squad->pivot->story_points }})</flux:text>
                                            @endif
                                        </div>
                                        @endforeach
                                    </div>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">Story Points:</flux:text>
                                    <div class="w-24">
                                        <flux:input type="number" wire:change.debounce.500ms="updateEpicStoryPoints({{ $epic->id }}, {{ $singleSquadId }}, $event.target.value ? parseInt($event.target.value) : null)" value="{{ $epic->planned_story_points }}" min="0" placeholder="Points" />
                                    </div>
                                    <flux:button variant="ghost" size="sm" wire:click="removeEpicFromPlan({{ $epic->id }}, {{ $singleSquadId }})" icon="x-mark" class="text-red-600 dark:text-red-400">
                                        Remove
                                    </flux:button>
                                </div>
                            </div>
                        </flux:card>
                        @endforeach
                    </div>
                    @endif
                </div>

                <div class="relative my-8">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-zinc-300 dark:border-zinc-700"></div>
                    </div>
                    <div class="relative flex justify-center text-sm">
                        <span class="bg-white dark:bg-zinc-900 px-4 text-zinc-500 dark:text-zinc-400">Available to Add</span>
                    </div>
                </div>

                <div>
                    <div class="flex items-center gap-3 mb-5">
                        <h3 class="text-zinc-600 dark:text-zinc-400">Available Epics</h3>
                        <div class="flex-1 h-px bg-zinc-200 dark:bg-zinc-800"></div>
                        <flux:badge color="zinc" size="sm">{{ $availableEpics->count() }} epic{{ $availableEpics->count() !== 1 ? 's' : '' }}</flux:badge>
                    </div>

                    @if($availableEpics->isEmpty())
                    <flux:card class="border-2 border-dashed border-zinc-300 dark:border-zinc-700">
                        <div class="text-center py-8">
                            <flux:text class="text-zinc-500 dark:text-zinc-400">No available epics found for {{ $data['squad']->name }} in {{ $selectedQuarter }}.</flux:text>
                        </div>
                    </flux:card>
                    @else
                    <div class="space-y-2">
                        @foreach($availableEpics as $epic)
                        <flux:card class="p-4 opacity-90 hover:opacity-100 transition-opacity">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <flux:heading size="base">{{ $epic->title }}</flux:heading>
                                        <flux:badge :color="$epic->status->slug === 'completed' ? 'green' : ($epic->status->slug === 'in-progress' ? 'blue' : ($epic->status->slug === 'blocked' ? 'red' : 'zinc'))" size="sm">
                                            {{ $epic->status->name }}
                                        </flux:badge>
                                        @if($epic->squads->firstWhere('id', $singleSquadId)?->pivot->story_points)
                                        <flux:badge color="blue" size="sm">{{ $epic->squads->firstWhere('id', $singleSquadId)->pivot->story_points }} pts</flux:badge>
                                        @endif
                                        @if($epic->start_date && $epic->end_date)
                                        <flux:badge color="zinc" size="sm">Overlaps quarter</flux:badge>
                                        @endif
                                    </div>
                                    @if($epic->description)
                                    <flux:text class="text-xs text-zinc-500 dark:text-zinc-400 truncate mt-1">{{ $epic->description }}</flux:text>
                                    @endif
                                    @php
                                        $otherSquads = $epic->squads->where('id', '!=', $singleSquadId);
                                    @endphp
                                    @if($otherSquads->isNotEmpty())
                                    <div class="flex items-center gap-1.5 mt-2 flex-wrap">
                                        <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">Also:</flux:text>
                                        @foreach($otherSquads as $squad)
                                        <div class="flex items-center gap-1 px-1.5 py-0.5 rounded bg-zinc-100 dark:bg-zinc-800">
                                            <div class="h-1.5 w-1.5 rounded-full" style="background-color: {{ $squad->color }}"></div>
                                            <flux:text class="text-xs text-zinc-600 dark:text-zinc-400">{{ $squad->name }}</flux:text>
                                            @if($squad->pivot->story_points)
                                            <flux:text class="text-xs font-medium text-zinc-700 dark:text-zinc-300">({{ $squad->pivot->story_points }})</flux:text>
                                            @endif
                                        </div>
                                        @endforeach
                                    </div>
                                    @endif
                                </div>
                                <flux:button size="sm" wire:click="addEpicToPlan({{ $epic->id }}, [{{ $singleSquadId }}])" icon="plus" variant="primary" class="shrink-0">
                                    Add to Plan
                                </flux:button>
                            </div>
                        </flux:card>
                        @endforeach
                    </div>
                    @endif
                </div>
            </div>
            @endif
        @endif
    @endif
</div>
