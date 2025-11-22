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
    public ?QuarterPlan $quarterPlan = null;

    public string $selectedQuarter = '';

    public ?int $selectedSquadId = null;

    public ?int $editingCapacityValue = null;

    public function mount(?QuarterPlan $plan = null): void
    {
        if ($plan) {
            // Edit existing plan
            $this->authorizePlan($plan);
            $this->quarterPlan = $plan;
            $this->selectedQuarter = $plan->getQuarterString();
            $this->selectedSquadId = $plan->squad_id;
            $this->editingCapacityValue = $plan->available_story_points;
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
        // Reload capacity for the new quarter
        if ($this->selectedSquadId) {
            $this->editingCapacityValue = $this->getSelectedSquadCapacity();
        }

        // Autosave: Create or update plan when quarter changes
        $this->savePlan();
    }

    public function updatedSelectedSquadId(): void
    {
        // Load capacity for the selected squad
        $this->editingCapacityValue = $this->getSelectedSquadCapacity();

        // Autosave: Create or update plan when squad changes
        $this->savePlan();
    }

    public function updatedEditingCapacityValue(): void
    {
        // Autosave capacity when value changes
        $this->saveCapacity();
    }

    public function savePlan(): void
    {
        if (! $this->selectedQuarter || ! $this->selectedSquadId) {
            return;
        }

        $team = Auth::user()->currentTeam;
        $parsed = QuarterPlan::parseQuarter($this->selectedQuarter);

        $this->quarterPlan = QuarterPlan::updateOrCreate(
            [
                'team_id' => $team->id,
                'squad_id' => $this->selectedSquadId,
                'year' => $parsed['year'],
                'quarter' => $parsed['quarter'],
            ],
            [
                'available_story_points' => $this->editingCapacityValue ?? 0,
            ]
        );
    }

    public function getSelectedSquadCapacity(): ?int
    {
        if (! $this->selectedSquadId) {
            return null;
        }

        if ($this->quarterPlan) {
            return $this->quarterPlan->available_story_points;
        }

        $team = Auth::user()->currentTeam;
        $parsed = QuarterPlan::parseQuarter($this->selectedQuarter);
        $quarterPlan = QuarterPlan::where('team_id', $team->id)
            ->where('squad_id', $this->selectedSquadId)
            ->where('year', $parsed['year'])
            ->where('quarter', $parsed['quarter'])
            ->first();

        return $quarterPlan?->available_story_points ?? 0;
    }

    public function getTotalAllocatedPoints(): int
    {
        if (! $this->selectedSquadId) {
            return 0;
        }

        $team = Auth::user()->currentTeam;

        return (int) DB::table('epic_squad')
            ->join('epics', 'epic_squad.epic_id', '=', 'epics.id')
            ->where('epic_squad.squad_id', $this->selectedSquadId)
            ->where('epic_squad.planned_quarter', $this->selectedQuarter)
            ->whereNotNull('epic_squad.story_points')
            ->where('epics.team_id', $team->id)
            ->sum('epic_squad.story_points');
    }

    public function getPlannedEpics(): \Illuminate\Support\Collection
    {
        if (! $this->selectedSquadId) {
            return collect();
        }

        $team = Auth::user()->currentTeam;
        $selectedQuarter = $this->selectedQuarter;

        // Get epics that are already planned for this squad/quarter
        return $team->epics()
            ->with(['status', 'squads'])
            ->whereHas('squads', function ($q) use ($selectedQuarter) {
                $q->where('squads.id', $this->selectedSquadId)
                    ->where('epic_squad.planned_quarter', $selectedQuarter);
            })
            ->get()
            ->map(function ($epic) {
                $plannedPivot = $epic->squads->firstWhere('id', $this->selectedSquadId)?->pivot;
                $epic->planned_story_points = $plannedPivot->story_points ?? null;

                return $epic;
            });
    }

    public function getAvailableEpics(): \Illuminate\Support\Collection
    {
        if (! $this->selectedSquadId) {
            return collect();
        }

        $team = Auth::user()->currentTeam;
        $selectedQuarter = $this->selectedQuarter;
        $quarterDates = QuarterPlan::getQuarterDates($selectedQuarter);

        // Get epics that are assigned to this squad, overlap the quarter, but aren't planned for this squad/quarter
        return $team->epics()
            ->with(['status', 'squads'])
            ->whereHas('squads', function ($q) {
                $q->where('squads.id', $this->selectedSquadId);
            })
            ->whereDoesntHave('squads', function ($q) use ($selectedQuarter) {
                $q->where('squads.id', $this->selectedSquadId)
                    ->where('epic_squad.planned_quarter', $selectedQuarter);
            })
            ->where(function ($query) use ($quarterDates) {
                // Epics that overlap the quarter dates
                $query->where(function ($q) use ($quarterDates) {
                    $q->whereNotNull('start_date')
                        ->whereNotNull('end_date')
                        ->where('start_date', '<=', $quarterDates['end'])
                        ->where('end_date', '>=', $quarterDates['start']);
                })
                    // OR all epics (for potential planning)
                    ->orWhere(function ($q) {
                        $q->whereNull('start_date')
                            ->orWhereNull('end_date');
                    });
            })
            ->get()
            ->map(function ($epic) {
                // Get existing story points for this squad (if any)
                $squadPivot = $epic->squads->firstWhere('id', $this->selectedSquadId)?->pivot;
                $epic->existing_story_points = $squadPivot->story_points ?? null;

                return $epic;
            });
    }

    public function saveCapacity(): void
    {
        if (! $this->selectedSquadId) {
            return;
        }

        $this->validate([
            'editingCapacityValue' => 'nullable|integer|min:0',
        ]);

        $team = Auth::user()->currentTeam;
        $parsed = QuarterPlan::parseQuarter($this->selectedQuarter);

        $this->quarterPlan = QuarterPlan::updateOrCreate(
            [
                'team_id' => $team->id,
                'squad_id' => $this->selectedSquadId,
                'year' => $parsed['year'],
                'quarter' => $parsed['quarter'],
            ],
            [
                'available_story_points' => $this->editingCapacityValue ?? 0,
            ]
        );
    }

    public function addEpicToPlan(int $epicId): void
    {
        if (! $this->selectedSquadId) {
            return;
        }

        $epic = Epic::findOrFail($epicId);
        $this->authorizeEpic($epic);

        // Get existing story points for this squad (if any)
        $existingPivot = $epic->squads()->where('squads.id', $this->selectedSquadId)->first()?->pivot;
        $existingPoints = $existingPivot->story_points ?? null;

        // Attach or update pivot, preserving existing story points
        if ($epic->squads()->where('squads.id', $this->selectedSquadId)->exists()) {
            $epic->squads()->updateExistingPivot($this->selectedSquadId, [
                'planned_quarter' => $this->selectedQuarter,
                'story_points' => $existingPoints, // Preserve existing points
            ]);
        } else {
            $epic->squads()->attach($this->selectedSquadId, [
                'planned_quarter' => $this->selectedQuarter,
                'story_points' => $existingPoints,
            ]);
        }
    }

    public function updateEpicStoryPoints(int $epicId, ?int $storyPoints): void
    {
        if (! $this->selectedSquadId) {
            return;
        }

        $epic = Epic::findOrFail($epicId);
        $this->authorizeEpic($epic);

        $epic->squads()->updateExistingPivot($this->selectedSquadId, [
            'story_points' => $storyPoints,
            'planned_quarter' => $this->selectedQuarter,
        ]);
    }

    public function removeEpicFromPlan(int $epicId): void
    {
        if (! $this->selectedSquadId) {
            return;
        }

        $epic = Epic::findOrFail($epicId);
        $this->authorizeEpic($epic);

        // Only remove the quarter assignment, preserve the story points
        $epic->squads()->updateExistingPivot($this->selectedSquadId, [
            'planned_quarter' => null,
            // Don't set story_points to null - preserve the sizing
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
        return [
            'squads' => Auth::user()->currentTeam->squads()->orderBy('name')->get(),
            'availableQuarters' => $this->getAvailableQuarters(),
            'selectedSquad' => $this->selectedSquadId ? Auth::user()->currentTeam->squads()->find($this->selectedSquadId) : null,
            'capacity' => $this->getSelectedSquadCapacity(),
            'totalAllocated' => $this->getTotalAllocatedPoints(),
            'plannedEpics' => $this->getPlannedEpics(),
            'availableEpics' => $this->getAvailableEpics(),
        ];
    }
};
?>

<div>
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 pt-8 pb-10">
        <div>
            <flux:button href="/planning" variant="ghost" icon="arrow-left" wire:navigate class="mb-3">Back to Plans</flux:button>
            <h1>{{ $quarterPlan ? 'Edit Plan' : 'Create Plan' }}</h1>
            <flux:text class="text-zinc-600 dark:text-zinc-400 mt-2">Configure capacity and allocate epics for your squad</flux:text>
        </div>
    </div>

    <!-- Steps 1 & 2: Quarter and Squad Selectors -->
    <div class="mb-6 flex flex-col sm:flex-row gap-8">
        <div class="w-36">
            <flux:field class="flex-1">
                <flux:label>Select Quarter</flux:label>
                <flux:select variant="listbox" wire:model.live.debounce.500ms="selectedQuarter" placeholder="Select quarter..." >
                    @foreach($availableQuarters as $quarter)
                    <flux:select.option value="{{ $quarter }}">{{ $quarter }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>
        </div>

        @if($selectedQuarter)
        <div class="w-36">
            <flux:field class="flex-1">
                <flux:label>Select Squad</flux:label>
                <flux:select variant="listbox" wire:model.live.debounce.500ms="selectedSquadId" placeholder="Select a squad...">
                    @foreach($squads as $squad)
                    <flux:select.option value="{{ $squad->id }}">{{ $squad->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>
        </div>
        @endif
    </div>

    @if($selectedSquadId && $selectedSquad)
    <!-- Step 3: Capacity & Allocation Bar -->
    <flux:card class="mb-6">
        @php
            $percentage = $capacity > 0 ? min(($totalAllocated / $capacity) * 100, 100) : 0;
            $isOverAllocated = $totalAllocated > $capacity;
            $remaining = $capacity - $totalAllocated;
        @endphp
        
        <div class="flex items-center gap-4">
            <!-- Squad Info & Capacity -->
            <div class="flex items-center gap-3 shrink-0">
                <div class="flex items-center gap-2">
                    <div class="h-3 w-3 rounded-full" style="background-color: {{ $selectedSquad->color }}"></div>
                    <flux:text class="font-medium">{{ $selectedSquad->name }}</flux:text>
                </div>
                <div class="h-4 w-px bg-zinc-300 dark:bg-zinc-700"></div>
                <div class="flex items-center gap-1.5 w-28">
                    <flux:input 
                        type="number" 
                        wire:model.blur="editingCapacityValue" 
                        wire:change="saveCapacity"
                        min="0" 
                        placeholder="0" 
                    />
                    <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">pts</flux:text>
                </div>
            </div>

            <!-- Progress Bar -->
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

            <!-- Status -->
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

    <!-- Epic Planning -->
    <div class="mb-6">
        <h2 class="mb-6">Plan Epics</h2>

        <!-- Planned Epics (Above the Line) -->
        <div class="mb-8">
            <div class="flex items-center gap-3 mb-5">
                <h3 class="text-green-600 dark:text-green-400">In Plan</h3>
                <div class="flex-1 h-px bg-green-200 dark:bg-green-900"></div>
                <flux:badge color="green" size="sm">{{ $plannedEpics->count() }} epic{{ $plannedEpics->count() !== 1 ? 's' : '' }}</flux:badge>
            </div>

            @if($plannedEpics->isEmpty())
            <flux:card class="border-2 border-dashed border-zinc-300 dark:border-zinc-700">
                <div class="text-center py-8">
                    <flux:text class="text-zinc-500 dark:text-zinc-400">No epics in plan yet. Add epics from below.</flux:text>
                </div>
            </flux:card>
            @else
            <div class="space-y-2">
                @foreach($plannedEpics as $epic)
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
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">Story Points:</flux:text>
                            <div class="w-24">
                                <flux:input type="number" wire:change.debounce.500ms="updateEpicStoryPoints({{ $epic->id }}, $event.target.value ? parseInt($event.target.value) : null)" value="{{ $epic->planned_story_points }}" min="0" placeholder="Points" />
                            </div>
                            <flux:button variant="ghost" size="sm" wire:click="removeEpicFromPlan({{ $epic->id }})" icon="x-mark" class="text-red-600 dark:text-red-400">
                                Remove
                            </flux:button>
                        </div>
                    </div>
                </flux:card>
                @endforeach
            </div>
            @endif
        </div>

        <!-- Divider -->
        <div class="relative my-8">
            <div class="absolute inset-0 flex items-center">
                <div class="w-full border-t border-zinc-300 dark:border-zinc-700"></div>
            </div>
            <div class="relative flex justify-center text-sm">
                <span class="bg-white dark:bg-zinc-900 px-4 text-zinc-500 dark:text-zinc-400">Available to Add</span>
            </div>
        </div>

        <!-- Available Epics (Below the Line) -->
        <div>
            <div class="flex items-center gap-3 mb-5">
                <h3 class="text-zinc-600 dark:text-zinc-400">Available Epics</h3>
                <div class="flex-1 h-px bg-zinc-200 dark:bg-zinc-800"></div>
                <flux:badge color="zinc" size="sm">{{ $availableEpics->count() }} epic{{ $availableEpics->count() !== 1 ? 's' : '' }}</flux:badge>
            </div>

            @if($availableEpics->isEmpty())
            <flux:card class="border-2 border-dashed border-zinc-300 dark:border-zinc-700">
                <div class="text-center py-8">
                    <flux:text class="text-zinc-500 dark:text-zinc-400">No available epics found for {{ $selectedSquad->name }} in {{ $selectedQuarter }}.</flux:text>
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
                                @if($epic->existing_story_points)
                                <flux:badge color="blue" size="sm">{{ $epic->existing_story_points }} pts</flux:badge>
                                @endif
                                @if($epic->start_date && $epic->end_date)
                                <flux:badge color="zinc" size="sm">Overlaps quarter</flux:badge>
                                @endif
                            </div>
                            @if($epic->description)
                            <flux:text class="text-xs text-zinc-500 dark:text-zinc-400 truncate mt-1">{{ $epic->description }}</flux:text>
                            @endif
                        </div>
                        <flux:button size="sm" wire:click="addEpicToPlan({{ $epic->id }})" icon="plus" variant="primary" class="shrink-0">
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
</div>
