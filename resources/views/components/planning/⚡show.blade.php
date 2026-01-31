<?php

use App\Models\BacklogConsideration;
use App\Models\Category;
use App\Models\Epic;
use App\Models\EpicQuarterPlan;
use App\Models\QuarterPlan;
use App\Models\QuarterPlanCategory;
use App\Models\Status;
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

    public array $categoryTargets = [];


    public function mount(?QuarterPlan $plan = null): void
    {
        if ($plan) {
            // Edit existing plan - load that squad into the array
            $this->authorizePlan($plan);
            $this->selectedQuarter = $plan->getQuarterString();
            $this->selectedSquadIds = [$plan->squad_id];
            $this->loadQuarterPlans();
        } else {
            // Create new plan - default to current quarter
            $now = Carbon::now();
            $currentQuarter = (int) ceil($now->month / 3);
            $currentYear = $now->year;

            $this->selectedQuarter = "Q{$currentQuarter}-{$currentYear}";

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
            $this->categoryTargets = [];
            return;
        }

        $team = Auth::user()->currentTeam;
        $parsed = QuarterPlan::parseQuarter($this->selectedQuarter);

        $plans = QuarterPlan::where('team_id', $team->id)
            ->whereIn('squad_id', $this->selectedSquadIds)
            ->where('year', $parsed['year'])
            ->where('quarter', $parsed['quarter'])
            ->with('categories')
            ->get()
            ->keyBy('squad_id');

        $this->quarterPlans = [];
        $this->editingCapacityValues = [];
        $this->categoryTargets = [];

        foreach ($this->selectedSquadIds as $squadId) {
            $plan = $plans->get($squadId);
            $this->quarterPlans[$squadId] = $plan;
            $this->editingCapacityValues[$squadId] = $plan?->available_story_points ?? 0;

            // Load category targets
            $this->categoryTargets[$squadId] = [];
            if ($plan) {
                foreach ($plan->categories as $category) {
                    $this->categoryTargets[$squadId][$category->id] = $category->pivot->allocated_story_points;
                }
            }
        }
    }

    public function getAvailableQuarters(): array
    {
        $quarters = [];
        $now = Carbon::now();
        $currentYear = $now->year;
        $currentQuarter = (int) ceil($now->month / 3);

        // Generate quarters from current quarter to 2 years ahead
        for ($year = $currentYear; $year <= $currentYear + 2; $year++) {
            $startQuarter = $year === $currentYear ? $currentQuarter : 1;
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

    public function loadCategoryTargets(int $squadId): void
    {
        $plan = $this->quarterPlans[$squadId] ?? null;
        if (! $plan) {
            return;
        }

        $this->categoryTargets[$squadId] = [];
        foreach ($plan->categories as $category) {
            $this->categoryTargets[$squadId][$category->id] = $category->pivot->allocated_story_points;
        }
    }

    public function saveCategoryTarget(int $squadId, int $categoryId, $points): void
    {
        $plan = $this->quarterPlans[$squadId] ?? null;
        if (! $plan) {
            return;
        }

        // Convert to int
        $points = $points !== '' && $points !== null ? max(0, (int) $points) : 0;

        // Sync or update the category allocation
        if ($points > 0) {
            $plan->categories()->syncWithoutDetaching([
                $categoryId => ['allocated_story_points' => $points],
            ]);
        } else {
            $plan->categories()->detach($categoryId);
        }

        $this->categoryTargets[$squadId][$categoryId] = $points;
    }

    /**
     * Batch query: Get allocated points by category for all selected squads.
     * Returns: [squad_id => [category_id => total_points]]
     */
    public function getAllocatedPointsByCategoryForAllSquads(): array
    {
        if (empty($this->selectedSquadIds) || ! $this->selectedQuarter) {
            return [];
        }

        $team = Auth::user()->currentTeam;

        $results = DB::table('epic_quarter_plans')
            ->join('epics', 'epic_quarter_plans.epic_id', '=', 'epics.id')
            ->whereIn('epic_quarter_plans.squad_id', $this->selectedSquadIds)
            ->where('epic_quarter_plans.quarter', $this->selectedQuarter)
            ->whereNotNull('epic_quarter_plans.story_points')
            ->where('epics.team_id', $team->id)
            ->groupBy('epic_quarter_plans.squad_id', 'epics.category_id')
            ->selectRaw('epic_quarter_plans.squad_id, epics.category_id, SUM(epic_quarter_plans.story_points) as total')
            ->get();

        // Transform into nested array: [squad_id => [category_id => total]]
        $bySquad = [];
        foreach ($this->selectedSquadIds as $squadId) {
            $bySquad[$squadId] = [];
        }

        foreach ($results as $row) {
            $bySquad[$row->squad_id][$row->category_id] = (int) $row->total;
        }

        return $bySquad;
    }

    /**
     * Batch query: Get total allocated points for all selected squads.
     * Returns: [squad_id => total_points]
     */
    public function getTotalAllocatedPointsForAllSquads(): array
    {
        if (empty($this->selectedSquadIds) || ! $this->selectedQuarter) {
            return [];
        }

        $team = Auth::user()->currentTeam;

        $results = DB::table('epic_quarter_plans')
            ->join('epics', 'epic_quarter_plans.epic_id', '=', 'epics.id')
            ->whereIn('epic_quarter_plans.squad_id', $this->selectedSquadIds)
            ->where('epic_quarter_plans.quarter', $this->selectedQuarter)
            ->whereNotNull('epic_quarter_plans.story_points')
            ->where('epics.team_id', $team->id)
            ->groupBy('epic_quarter_plans.squad_id')
            ->selectRaw('epic_quarter_plans.squad_id, SUM(epic_quarter_plans.story_points) as total')
            ->pluck('total', 'squad_id');

        // Ensure all selected squads have an entry (even if 0)
        $allocations = [];
        foreach ($this->selectedSquadIds as $squadId) {
            $allocations[$squadId] = (int) ($results[$squadId] ?? 0);
        }

        return $allocations;
    }

    public function getActualPointsByCategory(int $squadId): array
    {
        $team = Auth::user()->currentTeam;

        return DB::table('epic_quarter_plans')
            ->join('epics', 'epic_quarter_plans.epic_id', '=', 'epics.id')
            ->where('epic_quarter_plans.squad_id', $squadId)
            ->where('epic_quarter_plans.quarter', $this->selectedQuarter)
            ->whereNotNull('epic_quarter_plans.story_points')
            ->where('epics.team_id', $team->id)
            ->groupBy('epics.category_id')
            ->selectRaw('epics.category_id, SUM(epic_quarter_plans.story_points) as total')
            ->pluck('total', 'category_id')
            ->toArray();
    }

    public function getTotalAllocatedPointsForSquad(int $squadId): int
    {
        $team = Auth::user()->currentTeam;

        return (int) DB::table('epic_quarter_plans')
            ->join('epics', 'epic_quarter_plans.epic_id', '=', 'epics.id')
            ->where('epic_quarter_plans.squad_id', $squadId)
            ->where('epic_quarter_plans.quarter', $this->selectedQuarter)
            ->whereNotNull('epic_quarter_plans.story_points')
            ->where('epics.team_id', $team->id)
            ->sum('epic_quarter_plans.story_points');
    }

    public function getPlannedEpicsForSquad(int $squadId): \Illuminate\Support\Collection
    {
        $team = Auth::user()->currentTeam;
        $selectedQuarter = $this->selectedQuarter;

        return $team->epics()
            ->with([
                'status',
                'squads',
                'category',
                // Constrained eager load: load ALL quarter plans for this squad (needed for "other quarters" calculation in modal)
                'quarterPlans' => fn ($q) => $q->where('squad_id', $squadId),
            ])
            ->whereHas('quarterPlans', function ($q) use ($squadId, $selectedQuarter) {
                $q->where('squad_id', $squadId)
                    ->where('quarter', $selectedQuarter);
            })
            ->join('epic_quarter_plans as eqp', function ($join) use ($squadId, $selectedQuarter) {
                $join->on('epics.id', '=', 'eqp.epic_id')
                    ->where('eqp.squad_id', '=', $squadId)
                    ->where('eqp.quarter', '=', $selectedQuarter);
            })
            ->join('epic_squad as es', function ($join) use ($squadId) {
                $join->on('epics.id', '=', 'es.epic_id')
                    ->where('es.squad_id', '=', $squadId);
            })
            ->orderByRaw('eqp.sort_order IS NULL, eqp.sort_order ASC')
            ->select('epics.*', 'eqp.story_points as planned_story_points', 'eqp.sort_order', 'eqp.category_id as plan_category_id', 'es.estimated_story_points')
            ->get();
    }

    public function getBacklogEpicsForSquad(int $squadId): array
    {
        $team = Auth::user()->currentTeam;
        $selectedQuarter = $this->selectedQuarter;
        $quarterDates = QuarterPlan::getQuarterDates($selectedQuarter);

        // Get consideration records with sort_order (already ordered by Sortable trait's global scope)
        $considerations = BacklogConsideration::where('squad_id', $squadId)
            ->where('quarter', $selectedQuarter)
            ->get()
            ->keyBy('epic_id');

        $consideringIds = $considerations->keys()->toArray();

        // Epics assigned to this squad but NOT in this quarter's plan
        // Uses epic_squad dates (squad-specific timeline) for quarter overlap filtering
        $epics = $team->epics()
            ->with([
                'status',
                'squads',
                'category',
                // Constrained eager load: only load quarter plans for this squad
                'quarterPlans' => fn ($q) => $q->where('squad_id', $squadId),
            ])
            ->join('epic_squad as es', function ($join) use ($squadId) {
                $join->on('epics.id', '=', 'es.epic_id')
                    ->where('es.squad_id', '=', $squadId);
            })
            // Filter out epics already planned for this squad in this quarter
            ->whereDoesntHave('quarterPlans', function ($q) use ($squadId, $selectedQuarter) {
                $q->where('squad_id', $squadId)
                    ->where('quarter', $selectedQuarter);
            })
            ->where(function ($query) use ($quarterDates) {
                // Either squad assignment overlaps the quarter OR has no dates set
                $query->where(function ($q) use ($quarterDates) {
                    $q->whereNotNull('es.start_date')
                        ->whereNotNull('es.end_date')
                        ->where('es.start_date', '<=', $quarterDates['end'])
                        ->where('es.end_date', '>=', $quarterDates['start']);
                })
                ->orWhere(function ($q) {
                    $q->whereNull('es.start_date')
                        ->orWhereNull('es.end_date');
                });
            })
            ->select('epics.*', 'es.start_date as squad_start_date', 'es.end_date as squad_end_date', 'es.estimated_story_points')
            ->get()
            ->map(function ($epic) use ($selectedQuarter, $consideringIds, $considerations) {
                // Get story points from any existing quarter plan for this squad
                $currentQuarterPlan = $epic->quarterPlans->where('quarter', $selectedQuarter)->first();
                $anyPlan = $epic->quarterPlans->first();
                $epic->existing_story_points = $currentQuarterPlan?->story_points ?? $anyPlan?->story_points ?? null;
                $epic->is_considering = in_array($epic->id, $consideringIds);
                // Add sort_order for considering items
                $epic->consideration_sort_order = $considerations->get($epic->id)?->sort_order ?? 0;
                // estimated_story_points already selected from join
                return $epic;
            })
            ->values();

        // Split into considering (sorted by sort_order) and other
        return [
            'considering' => $epics->filter(fn ($e) => $e->is_considering)
                ->sortBy('consideration_sort_order')
                ->values(),
            'other' => $epics->filter(fn ($e) => ! $e->is_considering)->values(),
        ];
    }

    public function toggleConsideration(int $epicId, int $squadId): void
    {
        if (! in_array($squadId, $this->selectedSquadIds)) {
            return;
        }

        $epic = Epic::findOrFail($epicId);
        $this->authorizeEpic($epic);

        $existing = BacklogConsideration::where('epic_id', $epicId)
            ->where('squad_id', $squadId)
            ->where('quarter', $this->selectedQuarter)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            BacklogConsideration::create([
                'epic_id' => $epicId,
                'squad_id' => $squadId,
                'quarter' => $this->selectedQuarter,
            ]);
        }
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
            ->with(['status', 'squads', 'category', 'quarterPlans'])
            ->get()
            ->filter(function ($epic) use ($selectedSquadIds, $selectedQuarter) {
                $plannedCount = $epic->quarterPlans
                    ->whereIn('squad_id', $selectedSquadIds)
                    ->where('quarter', $selectedQuarter)
                    ->count();
                return $plannedCount >= 2;
            })
            ->map(function ($epic) use ($selectedSquadIds, $selectedQuarter) {
                $squadStoryPoints = [];
                foreach ($epic->quarterPlans->whereIn('squad_id', $selectedSquadIds)->where('quarter', $selectedQuarter) as $plan) {
                    $squadStoryPoints[$plan->squad_id] = $plan->story_points;
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
            ->with(['status', 'squads', 'category', 'quarterPlans'])
            ->whereHas('quarterPlans', function ($q) use ($squadId, $selectedQuarter) {
                $q->where('squad_id', $squadId)
                    ->where('quarter', $selectedQuarter);
            })
            ->get()
            ->filter(function ($epic) use ($otherSquadIds, $selectedQuarter) {
                // Filter out epics planned for any other selected squad
                return ! $epic->quarterPlans
                    ->whereIn('squad_id', $otherSquadIds)
                    ->where('quarter', $selectedQuarter)
                    ->isNotEmpty();
            })
            ->map(function ($epic) use ($squadId, $selectedQuarter) {
                $quarterPlan = $epic->quarterPlans
                    ->where('squad_id', $squadId)
                    ->where('quarter', $selectedQuarter)
                    ->first();
                $epic->planned_story_points = $quarterPlan->story_points ?? null;

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
            ->with(['status', 'squads', 'category', 'quarterPlans'])
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

                // Track which squads already have it planned (via quarter plans)
                $epic->planned_for_squad_ids = $epic->quarterPlans
                    ->whereIn('squad_id', $selectedSquadIds)
                    ->where('quarter', $selectedQuarter)
                    ->pluck('squad_id')
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

            // Ensure squad assignment exists
            if (! $epic->squads()->where('squads.id', $squadId)->exists()) {
                $epic->squads()->attach($squadId);
            }

            // Get estimated points from epic_squad pivot
            $squadAssignment = $epic->squads()->where('squads.id', $squadId)->first();
            $estimatedPoints = $squadAssignment?->pivot?->estimated_story_points;

            // Calculate already committed points across all other quarters for this squad
            $committedOtherQuarters = EpicQuarterPlan::where('epic_id', $epicId)
                ->where('squad_id', $squadId)
                ->where('quarter', '!=', $this->selectedQuarter)
                ->sum('story_points') ?? 0;

            // Pre-fill with remaining points (estimate - already committed), or null if no estimate
            $remainingPoints = null;
            if ($estimatedPoints !== null) {
                $remainingPoints = max(0, $estimatedPoints - $committedOtherQuarters);
            }

            // Create quarter plan if doesn't exist (don't overwrite existing story_points)
            EpicQuarterPlan::firstOrCreate(
                [
                    'epic_id' => $epicId,
                    'squad_id' => $squadId,
                    'quarter' => $this->selectedQuarter,
                ],
                [
                    'category_id' => $epic->category_id,
                    'story_points' => $remainingPoints,
                ]
            );
        }
    }

    public function addEpicToAllSquads(int $epicId): void
    {
        $epic = Epic::findOrFail($epicId);
        $assignedSquadIds = $epic->squads->pluck('id')->toArray();
        $targetSquadIds = array_intersect($this->selectedSquadIds, $assignedSquadIds);
        $this->addEpicToPlan($epicId, $targetSquadIds);
    }

    public function updateEpicStoryPoints(int $epicId, int $squadId, $storyPoints): void
    {
        if (! in_array($squadId, $this->selectedSquadIds)) {
            return;
        }

        $epic = Epic::findOrFail($epicId);
        $this->authorizeEpic($epic);

        // Convert to int or null
        $storyPoints = $storyPoints !== '' && $storyPoints !== null ? (int) $storyPoints : null;

        // Update the quarter plan for this specific quarter
        EpicQuarterPlan::where('epic_id', $epicId)
            ->where('squad_id', $squadId)
            ->where('quarter', $this->selectedQuarter)
            ->update(['story_points' => $storyPoints]);
    }

    public function updateEpicFromPopover(int $epicId, int $squadId, $title, $storyPoints, $estimatedPoints, $statusId, $categoryId, $description): void
    {
        $epic = Epic::findOrFail($epicId);
        $this->authorizeEpic($epic);

        // Update epic fields (category is now per-plan, not per-epic)
        $epic->update([
            'title' => $title,
            'status_id' => $statusId,
            'description' => $description ?: null,
        ]);

        // Update squad assignment with estimated points
        if (in_array($squadId, $this->selectedSquadIds)) {
            $estimatedPoints = $estimatedPoints !== '' && $estimatedPoints !== null ? (int) $estimatedPoints : null;
            $epic->squads()->updateExistingPivot($squadId, [
                'estimated_story_points' => $estimatedPoints,
            ]);

            // Only update quarter plan if one already exists (don't auto-add backlog epics to plan)
            $quarterPlan = EpicQuarterPlan::where('epic_id', $epicId)
                ->where('squad_id', $squadId)
                ->where('quarter', $this->selectedQuarter)
                ->first();

            if ($quarterPlan) {
                $storyPoints = $storyPoints !== '' && $storyPoints !== null ? (int) $storyPoints : null;
                $quarterPlan->update([
                    'story_points' => $storyPoints,
                    'category_id' => $categoryId ?: null,
                ]);
            }
        }
    }

    public function removeEpicFromPlan(int $epicId, int $squadId): void
    {
        if (! in_array($squadId, $this->selectedSquadIds)) {
            return;
        }

        $epic = Epic::findOrFail($epicId);
        $this->authorizeEpic($epic);

        // Delete the quarter plan for this specific quarter only (keeps squad assignment)
        EpicQuarterPlan::where('epic_id', $epicId)
            ->where('squad_id', $squadId)
            ->where('quarter', $this->selectedQuarter)
            ->delete();
    }

    public function addCategoryToPlan(int $categoryId, int $squadId): void
    {
        if (! in_array($squadId, $this->selectedSquadIds)) {
            return;
        }

        $plan = $this->quarterPlans[$squadId] ?? null;
        if (! $plan) {
            return;
        }

        // Check category belongs to team
        $category = Auth::user()->currentTeam->categories()->find($categoryId);
        if (! $category) {
            return;
        }

        // Add category to plan if not already added
        if (! $plan->categories()->where('category_id', $categoryId)->exists()) {
            QuarterPlanCategory::create([
                'quarter_plan_id' => $plan->id,
                'category_id' => $categoryId,
                'allocated_story_points' => 0,
            ]);
        }
    }

    public function removeCategoryFromPlan(int $categoryId, int $squadId): void
    {
        if (! in_array($squadId, $this->selectedSquadIds)) {
            return;
        }

        $plan = $this->quarterPlans[$squadId] ?? null;
        if (! $plan) {
            return;
        }

        $plan->categories()->detach($categoryId);
    }

    public function sortCategory(int $categoryId, int $position, int $squadId): void
    {
        if (! in_array($squadId, $this->selectedSquadIds)) {
            return;
        }

        $plan = $this->quarterPlans[$squadId] ?? null;
        if (! $plan) {
            return;
        }

        $pivotRecord = QuarterPlanCategory::where('quarter_plan_id', $plan->id)
            ->where('category_id', $categoryId)
            ->first();

        if ($pivotRecord) {
            $pivotRecord->move($position);
        }
    }

    public function sort($item, $position, $column, $squadId = null): void
    {
        $squadId = $squadId ?? $this->selectedSquadIds[0] ?? null;
        if (! $squadId || ! in_array($squadId, $this->selectedSquadIds)) {
            return;
        }

        $team = Auth::user()->currentTeam;
        $epicId = (int) $item;

        // Verify epic belongs to team
        $epic = Epic::where('id', $epicId)->where('team_id', $team->id)->first();
        if (! $epic) {
            return;
        }

        // Find existing quarter plan for THIS quarter
        $quarterPlan = EpicQuarterPlan::where('epic_id', $epicId)
            ->where('squad_id', $squadId)
            ->where('quarter', $this->selectedQuarter)
            ->first();

        // Handle backlog sections (considering and other-backlog)
        if ($column === 'considering' || $column === 'other-backlog') {
            // Remove from plan if it was planned
            if ($quarterPlan) {
                $quarterPlan->delete();
            }

            // Find existing consideration record
            $consideration = BacklogConsideration::where('epic_id', $epicId)
                ->where('squad_id', $squadId)
                ->where('quarter', $this->selectedQuarter)
                ->first();

            if ($column === 'considering') {
                if ($consideration) {
                    // Already considering - just move to new position
                    $consideration->move($position);
                } else {
                    // Add to considering (Sortable trait auto-assigns sort_order)
                    $consideration = BacklogConsideration::create([
                        'epic_id' => $epicId,
                        'squad_id' => $squadId,
                        'quarter' => $this->selectedQuarter,
                    ]);
                    // Move to specified position after creation
                    $consideration->move($position);
                }
            } elseif ($column === 'other-backlog' && $consideration) {
                // Remove from considering
                $consideration->delete();
            }

            return;
        }

        // Column is a category ID (or 'uncategorized')
        $categoryId = $column === 'uncategorized' ? null : (int) $column;

        // Ensure squad assignment exists
        if (! $epic->squads()->where('squads.id', $squadId)->exists()) {
            $epic->squads()->attach($squadId);
        }

        // Add to quarter plan if not already in this quarter
        if (! $quarterPlan) {
            // Get estimated points from epic_squad pivot
            $squadAssignment = $epic->squads()->where('squads.id', $squadId)->first();
            $estimatedPoints = $squadAssignment?->pivot?->estimated_story_points;

            // Calculate already committed points across all other quarters for this squad
            $committedOtherQuarters = EpicQuarterPlan::where('epic_id', $epicId)
                ->where('squad_id', $squadId)
                ->where('quarter', '!=', $this->selectedQuarter)
                ->sum('story_points') ?? 0;

            // Pre-fill with remaining points (estimate - already committed), or null if no estimate
            $remainingPoints = null;
            if ($estimatedPoints !== null) {
                $remainingPoints = max(0, $estimatedPoints - $committedOtherQuarters);
            }

            $quarterPlan = EpicQuarterPlan::create([
                'epic_id' => $epicId,
                'category_id' => $categoryId,
                'squad_id' => $squadId,
                'quarter' => $this->selectedQuarter,
                'story_points' => $remainingPoints,
            ]);
        }

        // Update plan's category if changed (category is per-plan, not per-epic)
        if ($quarterPlan->category_id !== $categoryId) {
            $quarterPlan->update(['category_id' => $categoryId]);
        }

        $quarterPlan->move($position);
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

        // Batch fetch aggregate data for all squads (2 queries instead of N*2)
        $allocatedBySquad = $this->getTotalAllocatedPointsForAllSquads();
        $actualByCategoryBySquad = $this->getAllocatedPointsByCategoryForAllSquads();

        // Build capacity and epic data per squad
        $squadData = [];
        foreach ($this->selectedSquadIds as $squadId) {
            $squad = $squads->find($squadId);
            if (! $squad) {
                continue;
            }

            $capacity = $this->editingCapacityValues[$squadId] ?? 0;
            $allocated = $allocatedBySquad[$squadId] ?? 0;

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
                'backlog_epics' => $this->getBacklogEpicsForSquad($squadId),
                'actual_by_category' => $actualByCategoryBySquad[$squadId] ?? [],
            ];
        }

        $allCategories = Auth::user()->currentTeam->categories()->ordered()->get();
        $statuses = Status::orderBy('order')->get();

        // Get plan categories for single squad view (ordered by sort_order)
        $planCategories = collect();
        $availableCategoriesForPlan = collect();
        if (! $isMultiSquadView && ! empty($this->selectedSquadIds)) {
            $singleSquadId = $this->selectedSquadIds[0];
            $plan = $this->quarterPlans[$singleSquadId] ?? null;
            if ($plan) {
                // Refresh the plan's categories to get updated sort_order
                $plan->load('categories');
                $planCategories = $plan->categories;
                $planCategoryIds = $planCategories->pluck('id')->toArray();
                $availableCategoriesForPlan = $allCategories->whereNotIn('id', $planCategoryIds);
            }
        }

        // Use plan categories if any are selected, otherwise fall back to all categories
        $categories = $planCategories->isNotEmpty() ? $planCategories : $allCategories;

        return [
            'squads' => $squads,
            'selectedSquads' => $selectedSquads,
            'selectedSquadIds' => $this->selectedSquadIds,
            'squadData' => $squadData,
            'sharedEpics' => $this->getSharedPlannedEpics(),
            'availableEpics' => $this->getAvailableEpics(),
            'availableQuarters' => $this->getAvailableQuarters(),
            'isMultiSquadView' => $isMultiSquadView,
            'categories' => $categories,
            'allCategories' => $allCategories,
            'planCategories' => $planCategories,
            'availableCategoriesForPlan' => $availableCategoriesForPlan,
            'statuses' => $statuses,
            'categoryTargets' => $this->categoryTargets,
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

            {{-- Summary Stats Bar --}}
            @php
                $totalCapacity = collect($squadData)->sum('capacity');
                $totalAllocatedMulti = collect($squadData)->sum('allocated');
                $totalPlanned = collect($squadData)->sum(fn($d) => $d['planned_epics']->count());
                $overallPercentage = $totalCapacity > 0 ? round(($totalAllocatedMulti / $totalCapacity) * 100) : 0;
                $anyOverAllocated = collect($squadData)->contains('is_over_allocated', true);
            @endphp
            <div class="flex flex-wrap items-center gap-4 mb-4 px-4 py-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg border border-zinc-200 dark:border-zinc-700">
                <div class="flex items-center gap-2">
                    <flux:icon.user-group variant="mini" class="text-zinc-500" />
                    <flux:text class="font-medium">{{ count($squadData) }} squads</flux:text>
                </div>
                <flux:separator vertical class="h-5" />
                <div class="flex items-center gap-1.5">
                    <flux:text class="text-sm text-zinc-500">Combined:</flux:text>
                    <flux:text class="text-sm font-medium tabular-nums {{ $anyOverAllocated ? 'text-red-600' : '' }}">
                        {{ $totalAllocatedMulti }}/{{ $totalCapacity }} pts
                    </flux:text>
                    <span class="text-xs text-zinc-400">({{ $overallPercentage }}%)</span>
                </div>
                <flux:separator vertical class="h-5" />
                <div class="flex items-center gap-1.5">
                    <flux:icon.clipboard-document-check variant="micro" class="text-green-600" />
                    <flux:text class="text-sm">{{ $totalPlanned }} planned</flux:text>
                </div>
                @if($sharedEpics->isNotEmpty())
                <flux:separator vertical class="h-5" />
                <div class="flex items-center gap-1.5">
                    <flux:icon.link variant="micro" class="text-purple-500" />
                    <flux:text class="text-sm">{{ $sharedEpics->count() }} shared</flux:text>
                </div>
                @endif
                @if($anyOverAllocated)
                <flux:badge color="red" size="sm">Some squads over capacity</flux:badge>
                @endif
            </div>

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

                    <div class="flex items-center gap-2 mb-1">
                        <div class="flex-1 h-2 bg-zinc-100 dark:bg-zinc-800 rounded-full overflow-hidden">
                            <div
                                class="h-full transition-all rounded-full {{ $data['is_over_allocated'] ? 'bg-red-500' : 'bg-green-500' }}"
                                style="width: {{ $data['is_over_allocated'] ? 100 : $data['percentage'] }}%"
                            ></div>
                        </div>
                        <flux:text class="text-xs font-medium tabular-nums shrink-0 {{ $data['is_over_allocated'] ? 'text-red-600 dark:text-red-400' : 'text-zinc-600 dark:text-zinc-400' }}">
                            {{ $data['allocated'] }}/{{ $data['capacity'] }}
                        </flux:text>
                    </div>

                    <flux:text class="text-xs {{ $data['is_over_allocated'] ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                        @if($data['is_over_allocated'])
                            {{ abs($data['remaining']) }} over capacity
                        @elseif($data['remaining'] === 0)
                            Fully allocated
                        @else
                            {{ $data['remaining'] }} pts remaining
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
                                            wire:change.debounce.500ms="updateEpicStoryPoints({{ $epic->id }}, {{ $squadId }}, $event.target.value)"
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
                            <div class="w-18 shrink-0">
                                <flux:input
                                    type="number"
                                    wire:change.debounce.500ms="updateEpicStoryPoints({{ $epic->id }}, {{ $squadId }}, $event.target.value)"
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
                        <div class="flex items-center gap-1.5 shrink-0">
                            @foreach($epic->squads->whereIn('id', array_keys($squadData)) as $squad)
                            <div class="flex items-center gap-1 px-1.5 py-0.5 rounded text-xs bg-zinc-100 dark:bg-zinc-700">
                                <div class="h-1.5 w-1.5 rounded-full" style="background-color: {{ $squad->color }}"></div>
                                <span class="text-zinc-600 dark:text-zinc-300 max-w-18 truncate">{{ $squad->name }}</span>
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
            {{-- Single Squad View - Kanban Layout --}}
            @php
                $singleSquadId = $selectedSquadIds[0] ?? null;
                $data = $squadData[$singleSquadId] ?? null;
                $singleSquadName = $squads->find($singleSquadId)?->name ?? 'Squad';
            @endphp

            @if($data)
            @php
                $percentage = $data['percentage'];
                $isOverAllocated = $data['is_over_allocated'];
                $remaining = $data['remaining'];
                $capacity = $data['capacity'];
                $totalAllocated = $data['allocated'];
                $actualByCategory = $data['actual_by_category'] ?? [];
                $backlogData = $data['backlog_epics'] ?? ['considering' => collect(), 'other' => collect()];
                $consideringEpics = $backlogData['considering'] ?? collect();
                $otherBacklogEpics = $backlogData['other'] ?? collect();
                $backlogEpicsCount = $consideringEpics->count() + $otherBacklogEpics->count();
                $plannedEpics = $data['planned_epics'] ?? collect();

                // Group planned epics by plan's category (not epic's global category)
                $plannedByCategory = $plannedEpics->groupBy(fn($e) => $e->plan_category_id ?? 'uncategorized');

                // Calculate category data
                $categoryData = [];
                foreach ($categories as $category) {
                    $target = $categoryTargets[$singleSquadId][$category->id] ?? 0;
                    $actual = $actualByCategory[$category->id] ?? 0;
                    $categoryData[$category->id] = [
                        'category' => $category,
                        'target' => $target,
                        'actual' => $actual,
                    ];
                }
            @endphp

            {{-- Summary Stats Bar --}}
            <div class="flex flex-wrap items-center gap-4 mb-4 px-4 py-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg border border-zinc-200 dark:border-zinc-700">
                <div class="flex items-center gap-2">
                    <div class="h-3 w-3 rounded-full" style="background-color: {{ $data['squad']->color }}"></div>
                    <flux:text class="font-medium">{{ $data['squad']->name }}</flux:text>
                </div>
                <flux:separator vertical class="h-5" />
                <div class="flex items-center gap-1.5">
                    <flux:text class="text-sm text-zinc-500">Capacity:</flux:text>
                    <flux:text class="text-sm font-medium tabular-nums {{ $isOverAllocated ? 'text-red-600' : '' }}">
                        {{ $totalAllocated }}/{{ $capacity }} pts
                    </flux:text>
                    @if($capacity > 0)
                    <span class="text-xs text-zinc-400">({{ round($percentage) }}%)</span>
                    @endif
                </div>
                <flux:separator vertical class="h-5" />
                <div class="flex items-center gap-1.5">
                    <flux:icon.clipboard-document-check variant="micro" class="text-green-600" />
                    <flux:text class="text-sm">{{ $plannedEpics->count() }} planned</flux:text>
                </div>
                <flux:separator vertical class="h-5" />
                <div class="flex items-center gap-1.5">
                    <flux:icon.inbox variant="micro" class="text-zinc-400" />
                    <flux:text class="text-sm">{{ $backlogEpicsCount }} in backlog</flux:text>
                </div>
                @if($isOverAllocated)
                <flux:badge color="red" size="sm">{{ abs($remaining) }} pts over</flux:badge>
                @elseif($remaining > 0)
                <flux:badge color="green" size="sm">{{ $remaining }} pts free</flux:badge>
                @endif
            </div>

            {{-- Collapsible Capacity & Categories Section --}}
            <div class="mb-6" x-data="{ expanded: false }" x-cloak>
                <flux:card class="overflow-hidden">
                    {{-- Collapsed Header (always visible) --}}
                    <button
                        type="button"
                        @click="expanded = !expanded"
                        class="w-full flex items-center justify-between gap-4 p-4 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors"
                    >
                        <div class="flex items-center gap-3">
                            <flux:icon.chevron-right
                                variant="mini"
                                class="text-zinc-400 transition-transform duration-200"
                                x-bind:class="{ 'rotate-90': expanded }"
                            />
                            <flux:text class="font-medium">Capacity & Categories</flux:text>
                        </div>
                        <div class="flex items-center gap-3 flex-1 max-w-md">
                            {{-- Mini progress bar --}}
                            <div class="flex-1 h-2 bg-zinc-100 dark:bg-zinc-800 rounded-full overflow-hidden {{ $isOverAllocated ? 'ring-1 ring-red-500' : '' }}">
                                @if($totalAllocated > 0 && $capacity > 0)
                                <div class="h-full flex" style="width: {{ min($percentage, 100) }}%">
                                    @foreach($categoryData as $catData)
                                        @if($catData['actual'] > 0)
                                        <div
                                            class="h-full"
                                            style="width: {{ ($catData['actual'] / $totalAllocated) * 100 }}%; background-color: {{ $catData['category']->color ?? '#6B7280' }};"
                                        ></div>
                                        @endif
                                    @endforeach
                                </div>
                                @endif
                            </div>
                            <flux:text class="text-sm tabular-nums shrink-0 {{ $isOverAllocated ? 'text-red-600' : 'text-zinc-600 dark:text-zinc-400' }}">
                                {{ $totalAllocated }}/{{ $capacity }}
                            </flux:text>
                        </div>
                    </button>

                    {{-- Expanded Content --}}
                    <div
                        x-show="expanded"
                        x-collapse
                        class="border-t border-zinc-100 dark:border-zinc-800"
                    >
                        <div class="p-4 space-y-4">
                            {{-- Capacity Input --}}
                            <div class="flex items-center gap-3">
                                <flux:label class="text-sm">Total Capacity:</flux:label>
                                <div class="w-24">
                                    <flux:input
                                        type="number"
                                        wire:model.blur="editingCapacityValues.{{ $singleSquadId }}"
                                        wire:change="saveCapacityForSquad({{ $singleSquadId }})"
                                        min="0"
                                        placeholder="0"
                                        size="sm"
                                    />
                                </div>
                                <flux:text class="text-sm text-zinc-500">story points</flux:text>
                            </div>

                            {{-- Stacked progress bar (larger) --}}
                            <div class="h-4 rounded-full overflow-hidden bg-zinc-100 dark:bg-zinc-800 {{ $isOverAllocated ? 'ring-2 ring-red-500' : '' }}">
                                @if($totalAllocated > 0 && $capacity > 0)
                                    <div class="h-full flex" style="width: {{ min($percentage, 100) }}%">
                                        @foreach($categoryData as $catData)
                                            @if($catData['actual'] > 0)
                                            <div
                                                class="h-full"
                                                style="width: {{ ($catData['actual'] / $totalAllocated) * 100 }}%; background-color: {{ $catData['category']->color ?? '#6B7280' }};"
                                                title="{{ $catData['category']->name }}: {{ $catData['actual'] }} pts"
                                            ></div>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            {{-- Category breakdown legend --}}
                            @if($totalAllocated > 0)
                            <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-600 dark:text-zinc-400">
                                @foreach($categoryData as $catData)
                                    @if($catData['actual'] > 0)
                                    <div class="flex items-center gap-1.5">
                                        <div class="h-2 w-2 rounded-sm" style="background-color: {{ $catData['category']->color ?? '#6B7280' }}"></div>
                                        <span>{{ $catData['category']->name }}</span>
                                        <span class="tabular-nums font-medium">{{ $catData['actual'] }}</span>
                                        <span class="text-zinc-400">({{ round(($catData['actual'] / $capacity) * 100) }}%)</span>
                                    </div>
                                    @endif
                                @endforeach
                            </div>
                            @endif

                            {{-- Category targets --}}
                            @if($categories->isNotEmpty())
                            <div class="pt-2">
                                <flux:text class="text-xs font-medium text-zinc-500 uppercase tracking-wide mb-3">Category Targets</flux:text>
                                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                                    @foreach($categoryData as $catId => $catData)
                                        @php
                                            $isOver = $catData['target'] > 0 && $catData['actual'] > $catData['target'];
                                        @endphp
                                        <div class="flex items-center gap-2 p-2 bg-zinc-50 dark:bg-zinc-800/50 rounded">
                                            <div class="h-3 w-3 rounded" style="background-color: {{ $catData['category']->color ?? '#6B7280' }}"></div>
                                            <div class="flex-1 min-w-0">
                                                <flux:text class="text-xs truncate">{{ $catData['category']->name }}</flux:text>
                                                <div class="flex items-center gap-1">
                                                    <flux:text class="text-xs font-medium tabular-nums {{ $isOver ? 'text-red-600' : '' }}">{{ $catData['actual'] }}</flux:text>
                                                    <span class="text-zinc-400 text-xs">/</span>
                                                    <div class="w-18">
                                                        <flux:input
                                                            type="number"
                                                            wire:change="saveCategoryTarget({{ $singleSquadId }}, {{ $catId }}, $event.target.value)"
                                                            value="{{ $catData['target'] ?: '' }}"
                                                            min="0"
                                                            placeholder="–"
                                                            size="sm"
                                                        />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            @endif
                        </div>
                    </div>
                </flux:card>
            </div>

            {{-- Category Management Header --}}
            @if($availableCategoriesForPlan->isNotEmpty() || $planCategories->isNotEmpty())
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-2">
                    @if($planCategories->isNotEmpty())
                    <flux:text class="text-sm text-zinc-500">{{ $planCategories->count() }} categories selected</flux:text>
                    <flux:text class="text-xs text-zinc-400">(drag to reorder)</flux:text>
                    @else
                    <flux:text class="text-sm text-zinc-500">Showing all categories</flux:text>
                    @endif
                </div>
                @if($availableCategoriesForPlan->isNotEmpty())
                <flux:dropdown>
                    <flux:button variant="ghost" size="sm" icon="plus">Add Category</flux:button>
                    <flux:menu>
                        @foreach($availableCategoriesForPlan as $availableCategory)
                        <flux:menu.item wire:click="addCategoryToPlan({{ $availableCategory->id }}, {{ $singleSquadId }})">
                            <div class="flex items-center gap-2">
                                <div class="h-2 w-2 rounded" style="background-color: {{ $availableCategory->color }}"></div>
                                {{ $availableCategory->name }}
                            </div>
                        </flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>
                @endif
            </div>
            @endif

            {{-- Category Columns for Planned Items --}}
            @php $canSortCategories = $planCategories->isNotEmpty(); @endphp
            <div
                class="flex gap-4 overflow-x-auto [&>[data-flux-kanban-column]]:flex-1"
                @if($canSortCategories)
                x-sort="$wire.sortCategory($item, $position, {{ $singleSquadId }})"
                x-sort:group="category-columns-{{ $singleSquadId }}"
                @endif
            >
                @foreach($categories as $category)
                    @php
                        $categoryEpics = $plannedByCategory->get($category->id, collect());
                        $categoryPoints = $categoryEpics->sum('planned_story_points');
                        $target = $categoryTargets[$singleSquadId][$category->id] ?? 0;
                        $isOver = $target > 0 && $categoryPoints > $target;
                    @endphp

                    <div
                        class="flex-1 min-w-[280px]"
                        @if($canSortCategories) x-sort:item="{{ $category->id }}" @endif
                    >
                        <div class="group/header {{ $canSortCategories ? 'cursor-grab active:cursor-grabbing' : '' }} px-2 pb-2 font-medium text-sm text-zinc-700 dark:text-zinc-300">
                            <div class="flex items-center gap-2 w-full">
                                <div class="h-3 w-3 rounded" style="background-color: {{ $category->color }}"></div>
                                <span class="font-medium">{{ $category->name }}</span>
                                <flux:badge :color="$isOver ? 'red' : 'zinc'" size="sm">
                                    {{ $categoryPoints }}{{ $target ? "/$target" : '' }} pts
                                </flux:badge>
                                @if($canSortCategories)
                                <button
                                    type="button"
                                    wire:click="removeCategoryFromPlan({{ $category->id }}, {{ $singleSquadId }})"
                                    wire:confirm="Remove '{{ $category->name }}' from this plan? Epics in this category will move to Uncategorized."
                                    class="ml-auto opacity-0 group-hover/header:opacity-100 p-1 rounded hover:bg-zinc-100 dark:hover:bg-zinc-800 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 transition-opacity"
                                    title="Remove category from plan"
                                >
                                    <flux:icon.x-mark variant="micro" />
                                </button>
                                @endif
                            </div>
                        </div>

                        <div
                            class="px-2 pb-2 flex flex-col gap-2"
                            x-sort="$wire.sort($item, $position, {{ $category->id }}, {{ $singleSquadId }})"
                            x-sort:group="planning-{{ $singleSquadId }}"
                        >
                            @foreach($categoryEpics as $epic)
                                <x-planning.kanban-card
                                    :epic="$epic"
                                    :squadId="$singleSquadId"
                                    :planned="true"
                                    :statuses="$statuses"
                                    :categories="$allCategories"
                                    :quarter="$this->selectedQuarter"
                                    :squadName="$singleSquadName"
                                />
                            @endforeach
                            {{-- Show one placeholder for the next slot (up to 3 total) --}}
                            @if($categoryEpics->count() < 3)
                            <div class="min-h-[100px] border-2 border-dashed border-zinc-300 dark:border-zinc-600 rounded-lg"></div>
                            @endif
                        </div>
                    </div>
                @endforeach

                {{-- Uncategorized Column --}}
                @php $uncategorized = $plannedByCategory->get('uncategorized', collect()); @endphp
                @if($uncategorized->isNotEmpty() || $categories->isEmpty())
                    <div class="flex-1 min-w-[280px]">
                        <div class="px-2 pb-2 font-medium text-sm text-zinc-700 dark:text-zinc-300">
                            <div class="flex items-center gap-2">
                                <flux:icon.question-mark-circle variant="micro" class="text-zinc-400" />
                                <span class="font-medium text-zinc-500">Uncategorized</span>
                                <flux:badge color="zinc" size="sm">{{ $uncategorized->sum('planned_story_points') }} pts</flux:badge>
                            </div>
                        </div>

                        <div
                            class="px-2 pb-2 flex flex-col gap-2"
                            x-sort="$wire.sort($item, $position, 'uncategorized', {{ $singleSquadId }})"
                            x-sort:group="planning-{{ $singleSquadId }}"
                        >
                            @foreach($uncategorized as $epic)
                                <x-planning.kanban-card
                                    :epic="$epic"
                                    :squadId="$singleSquadId"
                                    :planned="true"
                                    :statuses="$statuses"
                                    :categories="$allCategories"
                                    :quarter="$this->selectedQuarter"
                                    :squadName="$singleSquadName"
                                />
                            @endforeach
                            {{-- Show one placeholder for the next slot (up to 3 total) --}}
                            @if($uncategorized->count() < 3)
                            <div class="min-h-[100px] border-2 border-dashed border-zinc-300 dark:border-zinc-600 rounded-lg"></div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>

            {{-- Backlog Section (Below) --}}
            <div class="mt-6 border-t border-zinc-200 dark:border-zinc-700 pt-6 space-y-6">
                {{-- Considering Section --}}
                <div x-data="{ expanded: true }" class="space-y-3">
                    <div class="flex items-center justify-between">
                        <button
                            @click="expanded = !expanded"
                            class="flex items-center gap-2 text-left"
                        >
                            <flux:icon.chevron-right
                                class="h-4 w-4 text-zinc-400 transition-transform"
                                ::class="expanded && 'rotate-90'"
                            />
                            <flux:icon.star variant="mini" class="text-amber-500" />
                            <span class="font-medium text-zinc-600 dark:text-zinc-400">Considering</span>
                            <flux:badge color="amber" size="sm">{{ $consideringEpics->count() }}</flux:badge>
                        </button>
                    </div>

                    <div
                        x-show="expanded"
                        x-collapse
                        class="pb-2"
                    >
                        <div
                            class="flex flex-wrap gap-3 min-h-[40px]"
                            x-sort="$wire.sort($item, $position, 'considering', {{ $singleSquadId }})"
                            x-sort:group="planning-{{ $singleSquadId }}"
                        >
                            @foreach($consideringEpics as $epic)
                                <div
                                    class="w-96 shrink-0"
                                    x-sort:item="{{ $epic->id }}"
                                    wire:key="considering-{{ $epic->id }}"
                                >
                                    <x-planning.kanban-card
                                        :$epic
                                        :squadId="$singleSquadId"
                                        :isConsidering="true"
                                        :statuses="$statuses"
                                        :categories="$allCategories"
                                        :quarter="$this->selectedQuarter"
                                        :squadName="$singleSquadName"
                                        :sortable="false"
                                    />
                                </div>
                            @endforeach
                            {{-- Drop zone placeholder --}}
                            <div class="w-96 shrink-0 min-h-[100px] border-2 border-dashed border-amber-300 dark:border-amber-700 rounded-lg flex items-center justify-center">
                                <span class="text-zinc-400 text-sm">{{ $consideringEpics->isEmpty() ? 'Star items or drag here' : '' }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Other Backlog Section --}}
                <div x-data="{ expanded: {{ $consideringEpics->isEmpty() ? 'true' : 'false' }} }" class="space-y-3">
                    <div class="flex items-center justify-between">
                        <button
                            @click="expanded = !expanded"
                            class="flex items-center gap-2 text-left"
                        >
                            <flux:icon.chevron-right
                                class="h-4 w-4 text-zinc-400 transition-transform"
                                ::class="expanded && 'rotate-90'"
                            />
                            <flux:icon.inbox variant="mini" class="text-zinc-400" />
                            <span class="font-medium text-zinc-600 dark:text-zinc-400">Other Backlog</span>
                            <flux:badge color="zinc" size="sm">{{ $otherBacklogEpics->count() }}</flux:badge>
                        </button>
                    </div>

                    <div
                        x-show="expanded"
                        x-collapse
                        class="pb-2"
                    >
                        <div
                            class="flex flex-wrap gap-3 min-h-[40px]"
                            x-sort="$wire.sort($item, $position, 'other-backlog', {{ $singleSquadId }})"
                            x-sort:group="planning-{{ $singleSquadId }}"
                        >
                            @foreach($otherBacklogEpics as $epic)
                                <div
                                    class="w-96 shrink-0"
                                    x-sort:item="{{ $epic->id }}"
                                    wire:key="other-backlog-{{ $epic->id }}"
                                >
                                    <x-planning.kanban-card
                                        :$epic
                                        :squadId="$singleSquadId"
                                        :isConsidering="false"
                                        :statuses="$statuses"
                                        :categories="$allCategories"
                                        :quarter="$this->selectedQuarter"
                                        :squadName="$singleSquadName"
                                        :sortable="false"
                                    />
                                </div>
                            @endforeach
                            {{-- Drop zone placeholder --}}
                            <div class="w-96 shrink-0 min-h-[100px] border-2 border-dashed border-zinc-300 dark:border-zinc-600 rounded-lg flex items-center justify-center">
                                <span class="text-zinc-400 text-sm">{{ $otherBacklogEpics->isEmpty() ? 'Drop items here' : '' }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @endif
        @endif
    @endif
</div>
