<?php

use App\Models\Allocation;
use App\Models\Epic;
use App\Models\EpicComment;
use App\Models\EpicPause;
use App\Models\EpicQuarterPlan;
use App\Models\Status;
use App\Services\CapacityService;
use App\Support\Quarter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Session;
use Livewire\Component;

/**
 * The board. Columns are the team's statuses, in the order set on /statuses.
 *
 * An epic's column is stated, not inferred -- somebody dragged it there. What
 * the page adds is the check nobody can do by eye: whether the claim still
 * matches the grid.
 *
 * A status flagged "ask why on arrival" prompts for a reason when work lands
 * in it, which is how the pause record keeps getting written.
 */
new #[Layout('components.layouts.app.sidebar')] class extends Component
{
    /**
     * How much each card shows. Remembered per user, because the right answer
     * depends on how many epics you are carrying, not on the page.
     *
     * compact  -- title and faces only, for scanning a full board
     * standard -- adds category, priority and squad
     * detailed -- adds the description and names the people
     */
    #[Session(key: 'now.density')]
    public string $density = 'standard';

    public function setDensity(string $density): void
    {
        if (in_array($density, ['compact', 'standard', 'detailed'], true)) {
            $this->density = $density;
        }
    }

    /**
     * Which squad's cards the board shows. Remembered per user, like density.
     *
     * ''     -- everything
     * 'none' -- epics with no quarter plan, which belong to nobody yet
     * an id  -- epics any of whose plans point at that squad
     */
    #[Session(key: 'now.squad')]
    public string $squadFilter = '';

    /**
     * Columns the user has hidden to focus, as status ids. Remembered per
     * user, like the squad filter. Absence means shown, so an empty list is
     * the whole board and a brand-new status arrives visible.
     *
     * @var array<int, string>
     */
    #[Session(key: 'now.hiddenColumns')]
    public array $hiddenColumns = [];

    /**
     * Hide or show one column. The epics in a hidden column are untouched --
     * the board just stops drawing them.
     */
    public function toggleColumn(int $statusId): void
    {
        $id = (string) $this->teamStatus($statusId)->id;

        $this->hiddenColumns = in_array($id, $this->hiddenColumns, true)
            ? array_values(array_diff($this->hiddenColumns, [$id]))
            : [...$this->hiddenColumns, $id];
    }

    public function showAllColumns(): void
    {
        $this->hiddenColumns = [];
    }

    /**
     * Drives the flyout's visibility.
     *
     * Flux modals have no `open` prop -- they are controlled by wire:model (or
     * named modal-show events). Binding a plain boolean is the supported route.
     */
    public bool $showFlyout = false;

    /** Epic shown in the flyout, or null when it is closed. */
    public ?int $openEpicId = null;

    /** The flyout is showing the new-epic form rather than an existing epic. */
    public bool $creating = false;

    public string $newTitle = '';

    public string $newCategoryId = '';

    public string $newSquadId = '';

    public string $newStatusId = '';

    public string $newPriority = 'medium';

    public ?int $newPlannedPoints = null;

    /** Which form the flyout is showing: null or 'pause'. */
    public ?string $panel = null;

    /**
     * Which room of the flyout is open: 'details' or 'comments'. Facts and
     * conversation are different jobs, so they get different tabs rather
     * than one long scroll.
     */
    public string $flyoutTab = 'details';

    public string $pauseReason = '';

    public ?int $supersededById = null;

    public string $commentBody = '';

    /** Comment a reply composer is open under, or null for the top-level box. */
    public ?int $replyingToId = null;

    /** Comment whose body is being edited inline, or null. */
    public ?int $editingCommentId = null;

    public string $editCommentBody = '';

    // The open epic's own fields, editable in place. Like the edit page,
    // each one writes as it changes -- there is no save button.

    public string $editTitle = '';

    public string $editCategoryId = '';

    public string $editSquadId = '';

    public string $editPriority = 'medium';

    public string $editDescription = '';

    public ?int $editPlannedPoints = null;

    public string $editJiraEpicUrl = '';

    public string $editJpdIdeaUrl = '';

    // ------------------------------------------------------------- the flyout

    public function open(int $epicId): void
    {
        $epic = $this->teamEpic($epicId);

        $this->openEpicId = $epic->id;
        $this->showFlyout = true;
        $this->creating = false;
        $this->panel = null;
        $this->flyoutTab = 'details';
        $this->resetForms();
        $this->syncEditFields($epic);
    }

    public function close(): void
    {
        $this->showFlyout = false;
        $this->openEpicId = null;
        $this->creating = false;
        $this->panel = null;
        $this->resetForms();
    }

    /**
     * From the header button, or an empty column's placeholder. A column
     * passes its own status, and an active squad filter carries over --
     * the form opens already saying what the click meant.
     */
    public function newEpic(?int $statusId = null): void
    {
        $team = Auth::user()->currentTeam;

        $this->openEpicId = null;
        $this->creating = true;
        $this->showFlyout = true;
        $this->panel = null;
        $this->resetForms();

        $this->newTitle = '';
        $this->newCategoryId = (string) ($team->categories()->default()->first()?->id ?? '');
        $this->newSquadId = in_array($this->squadFilter, ['', 'none'], true) ? '' : $this->squadFilter;
        $this->newStatusId = (string) ($statusId !== null
            ? $this->teamStatus($statusId)->id
            : (Status::defaultFor($team)?->id ?? ''));
        $this->newPriority = 'medium';
        $this->newPlannedPoints = null;
    }

    public function createEpic(): void
    {
        $this->authorize('create', Epic::class);

        $this->validate([
            'newTitle' => 'required|string|max:255',
            'newCategoryId' => 'nullable|exists:categories,id',
            'newSquadId' => 'nullable|exists:squads,id',
            'newStatusId' => 'required|exists:statuses,id',
            'newPriority' => 'required|in:low,medium,high,critical',
            'newPlannedPoints' => 'nullable|integer|min:0',
        ], [
            'newTitle.required' => 'Give it a name.',
            'newStatusId.required' => 'Pick a column for it.',
        ]);

        $team = Auth::user()->currentTeam;
        $quarter = Quarter::current();
        $status = $this->teamStatus((int) $this->newStatusId);

        $epic = DB::transaction(function () use ($team, $quarter, $status) {
            $epic = Epic::create([
                'team_id' => $team->id,
                'category_id' => $this->newCategoryId ?: null,
                'status_id' => $status->id,
                'board_order' => ((int) $status->epics()->max('board_order')) + 1,
                'title' => $this->newTitle,
                'priority' => $this->newPriority,
                'start_date' => $quarter->start(),
                'end_date' => $quarter->end(),
            ]);

            // A squad plus a quarter is what gives the epic a home on the
            // roadmap; without it the epic still exists but floats.
            if ($this->newSquadId && $team->squads()->whereKey($this->newSquadId)->exists()) {
                EpicQuarterPlan::create([
                    'epic_id' => $epic->id,
                    'squad_id' => $this->newSquadId,
                    'year' => $quarter->year,
                    'quarter' => $quarter->quarter,
                    'planned_points' => $this->newPlannedPoints ?: null,
                ]);
            }

            return $epic;
        });

        $this->creating = false;
        $this->openEpicId = $epic->id;
        $this->resetForms();
        $this->syncEditFields($epic);

        $this->panel = $status->requires_reason ? 'pause' : null;
    }

    /** Dismissing via Esc, the close button or a click outside flips the model. */
    public function updatedShowFlyout(bool $value): void
    {
        if (! $value) {
            $this->close();
        }
    }

    /** Opens the flyout straight into the pause form. */
    public function explain(int $epicId): void
    {
        $this->open($epicId);
        $this->panel = 'pause';
    }

    public function showPanel(?string $panel): void
    {
        $this->panel = $this->panel === $panel ? null : $panel;
        $this->resetForms();
    }

    public function setFlyoutTab(string $tab): void
    {
        $this->flyoutTab = in_array($tab, ['details', 'comments'], true) ? $tab : 'details';
    }

    private function resetForms(): void
    {
        $this->pauseReason = '';
        $this->supersededById = null;
        $this->commentBody = '';
        $this->replyingToId = null;
        $this->editingCommentId = null;
        $this->editCommentBody = '';
        $this->resetErrorBag();
    }

    // -------------------------------------------------- editing in the flyout

    private function syncEditFields(Epic $epic): void
    {
        $plan = $epic->quarterPlans()->forQuarter(Quarter::current())->orderBy('id')->first();

        $this->editTitle = $epic->title;
        $this->editCategoryId = (string) ($epic->category_id ?? '');
        $this->editSquadId = (string) ($plan?->squad_id ?? '');
        $this->editPriority = $epic->priority ?? 'medium';
        $this->editDescription = (string) ($epic->description ?? '');
        $this->editPlannedPoints = $plan?->planned_points;
        $this->editJiraEpicUrl = $epic->jira_epic_url ?? '';
        $this->editJpdIdeaUrl = $epic->jpd_idea_url ?? '';
    }

    public function updatedEditDescription(): void
    {
        $epic = $this->teamEpic($this->openEpicId);
        $this->authorize('update', $epic);

        $this->validate(['editDescription' => 'nullable|string|max:65535']);

        $epic->update(['description' => $this->editDescription ?: null]);
    }

    /** Writes this quarter's planned points onto the plan the flyout shows. */
    public function updatedEditPlannedPoints(): void
    {
        $epic = $this->teamEpic($this->openEpicId);
        $this->authorize('update', $epic);

        $this->validate(['editPlannedPoints' => 'nullable|integer|min:0']);

        $epic->quarterPlans()->forQuarter(Quarter::current())->orderBy('id')->first()
            ?->update(['planned_points' => $this->editPlannedPoints]);
    }

    public function updatedEditTitle(): void
    {
        $epic = $this->teamEpic($this->openEpicId);
        $this->authorize('update', $epic);

        $this->validate(['editTitle' => 'required|string|max:255'], ['editTitle.required' => 'Give it a name.']);

        $epic->update(['title' => $this->editTitle]);
    }

    public function updatedEditCategoryId(): void
    {
        $epic = $this->teamEpic($this->openEpicId);
        $this->authorize('update', $epic);

        $categoryId = $this->editCategoryId ?: null;

        if ($categoryId && ! Auth::user()->currentTeam->categories()->whereKey($categoryId)->exists()) {
            abort(403);
        }

        $epic->update(['category_id' => $categoryId]);
    }

    public function updatedEditPriority(): void
    {
        $epic = $this->teamEpic($this->openEpicId);
        $this->authorize('update', $epic);

        $this->validate(['editPriority' => 'required|in:low,medium,high,critical']);

        $epic->update(['priority' => $this->editPriority]);
    }

    public function updatedEditJiraEpicUrl(): void
    {
        $epic = $this->teamEpic($this->openEpicId);
        $this->authorize('update', $epic);

        $this->validate(
            ['editJiraEpicUrl' => 'nullable|url:https|max:2048'],
            ['editJiraEpicUrl.url' => 'Paste the full https:// link from Jira.'],
        );

        $epic->update(['jira_epic_url' => trim($this->editJiraEpicUrl) ?: null]);
    }

    public function updatedEditJpdIdeaUrl(): void
    {
        $epic = $this->teamEpic($this->openEpicId);
        $this->authorize('update', $epic);

        $this->validate(
            ['editJpdIdeaUrl' => 'nullable|url:https|max:2048'],
            ['editJpdIdeaUrl.url' => 'Paste the full https:// link from Product Discovery.'],
        );

        $epic->update(['jpd_idea_url' => trim($this->editJpdIdeaUrl) ?: null]);
    }

    /**
     * Reassigns this quarter's plan to the chosen squad.
     *
     * Only the plan shown in the flyout is touched: swapping squads carries the
     * points over, clearing the squad drops the plan, and other quarters are
     * left alone.
     */
    public function updatedEditSquadId(): void
    {
        $epic = $this->teamEpic($this->openEpicId);
        $this->authorize('update', $epic);

        $team = Auth::user()->currentTeam;
        $quarter = Quarter::current();

        if ($this->editSquadId && ! $team->squads()->whereKey($this->editSquadId)->exists()) {
            abort(403);
        }

        DB::transaction(function () use ($epic, $quarter) {
            $current = $epic->quarterPlans()->forQuarter($quarter)->orderBy('id')->first();

            if (! $this->editSquadId) {
                $current?->delete();

                return;
            }

            $squadId = (int) $this->editSquadId;

            if ($current?->squad_id === $squadId) {
                return;
            }

            // A plan for the chosen squad may already exist on a multi-squad
            // epic; reuse it rather than tripping the unique key.
            $existing = $epic->quarterPlans()->forQuarter($quarter)->where('squad_id', $squadId)->first();

            if ($existing) {
                $current?->delete();
            } elseif ($current) {
                $current->update(['squad_id' => $squadId]);
            } else {
                EpicQuarterPlan::create([
                    'epic_id' => $epic->id,
                    'squad_id' => $squadId,
                    'year' => $quarter->year,
                    'quarter' => $quarter->quarter,
                    'planned_points' => $this->editPlannedPoints,
                ]);
            }
        });
    }

    // -------------------------------------------------------------- the board

    /**
     * Drag handler. Changing column is the whole point, so the status write
     * comes first and the ordering is rebuilt around it.
     */
    public function moveEpic(int $item, int $position, int $statusId): void
    {
        $epic = $this->teamEpic($item);
        $this->authorize('update', $epic);

        $status = $this->teamStatus($statusId);
        $changedColumn = $epic->status_id !== $status->id;

        DB::transaction(function () use ($epic, $status, $position, $changedColumn) {
            if ($changedColumn) {
                $epic->update(['status_id' => $status->id]);

                // Whatever the old pause was about, it ended when the epic moved.
                $epic->pauses()->open()->update(['resumed_at' => now()]);
            }

            $this->resequence($status, $epic, $position);
        });

        // Landing somewhere that wants an explanation asks for one now, while
        // the person who moved it still knows the answer.
        if ($changedColumn && $status->requires_reason) {
            $this->explain($epic->id);
        }
    }

    /** Whether the squad filter lets this epic onto the board. */
    private function passesSquadFilter(Epic $epic): bool
    {
        if ($this->squadFilter === '') {
            return true;
        }

        if ($this->squadFilter === 'none') {
            return $epic->quarterPlans->isEmpty();
        }

        return $epic->quarterPlans->contains('squad_id', (int) $this->squadFilter);
    }

    /** Rewrites board_order for one column with the moved epic slotted in. */
    private function resequence(Status $status, Epic $moved, int $position): void
    {
        $epics = $status->epics()->onBoard()->with('quarterPlans')->get()
            ->reject(fn ($epic) => $epic->id === $moved->id)
            ->values();

        // The drop position counts visible cards only. With a filter hiding
        // some of the column, anchor the insert on the card it landed in
        // front of rather than trusting the raw index.
        $anchor = $epics->filter(fn ($epic) => $this->passesSquadFilter($epic))->values()->get($position);

        $ids = $epics->pluck('id');
        $index = $anchor ? $ids->search($anchor->id) : $ids->count();

        $ids->splice(max(0, $index), 0, [$moved->id]);

        foreach ($ids as $order => $id) {
            Epic::whereKey($id)->update(['board_order' => $order]);
        }
    }

    // ----------------------------------------------------------- work actions

    /**
     * Puts one person on the epic from this week through the end of the
     * quarter. One click, no form -- trim or extend the weeks on the epic
     * page when the default is wrong. Does not touch which column it sits in.
     */
    public function assign(int $engineerId): void
    {
        $epic = $this->teamEpic($this->openEpicId);
        $this->authorize('update', $epic);

        abort_unless(
            Auth::user()->currentTeam->engineers()->whereKey($engineerId)->exists(),
            403,
        );

        $calendar = Auth::user()->currentTeam->weekCalendar();
        $weeks = collect($calendar->weeksIn(Quarter::current()))
            ->filter(fn ($week) => $week->gte($calendar->current()));

        DB::transaction(function () use ($epic, $engineerId, $weeks) {
            foreach ($weeks as $week) {
                Allocation::firstOrCreate(
                    ['engineer_id' => $engineerId, 'epic_id' => $epic->id, 'week_start' => $week->toDateString()],
                    ['share' => 1.0],
                );
            }

            // Someone being put on it means the pause is over.
            $epic->pauses()->open()->update(['resumed_at' => now()]);
        });
    }

    /**
     * Records why work stopped and clears bookings from this week forward.
     *
     * Past weeks are deliberately left alone -- they are the record of what was
     * actually spent, and deleting them would rewrite history.
     */
    public function pauseWork(): void
    {
        $epic = $this->teamEpic($this->openEpicId);
        $this->authorize('update', $epic);

        $this->validate([
            'pauseReason' => 'required|string|max:255',
            'supersededById' => 'nullable|exists:epics,id',
        ], [
            'pauseReason.required' => 'Say why it stopped — that is the part the grid cannot know.',
        ]);

        $capacity = CapacityService::for(Auth::user()->currentTeam);
        $week = $capacity->currentWeek();
        $wasStaffed = $capacity->isStaffedInWeek($epic);

        DB::transaction(function () use ($epic, $week, $capacity, $wasStaffed) {
            $this->clearFrom($epic, $week);

            EpicPause::create([
                'epic_id' => $epic->id,
                // Stopping it now pauses it now; something already quiet keeps
                // the date it actually went silent.
                'paused_at' => $wasStaffed ? $week : $this->pausedSince($capacity->weeksQuiet($epic)),
                'reason' => $this->pauseReason,
                'superseded_by_epic_id' => $this->supersededById,
            ]);

            // Move it into the column that asks for a reason, if there is one.
            $asks = Auth::user()->currentTeam->statuses()->where('requires_reason', true)->ordered()->first();

            if ($asks && $epic->status_id !== $asks->id) {
                $epic->update(['status_id' => $asks->id]);
            }
        });

        $this->panel = null;
        $this->resetForms();
    }

    /** Kept as the entry point the top-of-page prompt and its tests use. */
    public function recordPause(): void
    {
        $this->pauseWork();
    }

    /** Files the epic in the first column the team marked as finished. */
    public function markShipped(): void
    {
        $epic = $this->teamEpic($this->openEpicId);
        $this->authorize('update', $epic);

        $done = Auth::user()->currentTeam->statuses()->where('is_complete', true)->ordered()->first();

        if (! $done) {
            $this->addError('status', 'No status is marked as finished. Set one on the Statuses page.');

            return;
        }

        DB::transaction(function () use ($epic, $done) {
            $epic->update(['status_id' => $done->id]);
            $epic->pauses()->open()->update(['resumed_at' => now()]);
        });

        $this->panel = null;
    }

    public function reopen(): void
    {
        $epic = $this->teamEpic($this->openEpicId);
        $this->authorize('update', $epic);

        $target = Auth::user()->currentTeam->statuses()->where('is_complete', false)->ordered()->first();

        if ($target) {
            DB::transaction(function () use ($epic, $target) {
                $epic->update(['status_id' => $target->id]);
                $epic->pauses()->open()->update(['resumed_at' => now()]);
            });
        }
    }

    /** Takes one person off from this week forward, leaving their past weeks intact. */
    public function unstaff(int $engineerId): void
    {
        $epic = $this->teamEpic($this->openEpicId);
        $this->authorize('update', $epic);

        $this->clearFrom($epic, CapacityService::for(Auth::user()->currentTeam)->currentWeek(), $engineerId);
    }

    // --------------------------------------------------------------- comments

    public function addComment(): void
    {
        $epic = $this->teamEpic($this->openEpicId);
        $this->authorize('update', $epic);

        $this->validate([
            'commentBody' => 'required|string|max:5000',
        ], [
            'commentBody.required' => 'Say something first.',
        ]);

        $parentId = null;

        if ($this->replyingToId !== null) {
            $parent = $epic->comments()->findOr($this->replyingToId, fn () => abort(403));

            // Replying to a reply joins the same thread: threading is one
            // level deep, so everything re-roots onto the top-level comment.
            $parentId = $parent->parent_id ?? $parent->id;
        }

        EpicComment::create([
            'epic_id' => $epic->id,
            'user_id' => Auth::id(),
            'parent_id' => $parentId,
            'body' => $this->commentBody,
        ]);

        $this->commentBody = '';
        $this->replyingToId = null;
        $this->resetErrorBag('commentBody');
    }

    public function replyTo(int $commentId): void
    {
        // One shared commentBody serves both composers -- only one is ever
        // visible at a time, so toggling clears the draft either way.
        $this->replyingToId = $this->replyingToId === $commentId ? null : $commentId;
        $this->commentBody = '';
        $this->editingCommentId = null;
        $this->editCommentBody = '';
        $this->resetErrorBag(['commentBody', 'editCommentBody']);
    }

    public function editComment(int $commentId): void
    {
        $epic = $this->teamEpic($this->openEpicId);
        $comment = $epic->comments()->findOr($commentId, fn () => abort(403));

        $this->authorize('update', $comment);

        $this->editingCommentId = $comment->id;
        $this->editCommentBody = $comment->body;
        $this->replyingToId = null;
        $this->commentBody = '';
        $this->resetErrorBag(['commentBody', 'editCommentBody']);
    }

    public function cancelEditComment(): void
    {
        $this->editingCommentId = null;
        $this->editCommentBody = '';
        $this->resetErrorBag('editCommentBody');
    }

    public function updateComment(): void
    {
        $epic = $this->teamEpic($this->openEpicId);
        $comment = $epic->comments()->findOr($this->editingCommentId, fn () => abort(403));

        $this->authorize('update', $comment);

        $this->validate([
            'editCommentBody' => 'required|string|max:5000',
        ], [
            'editCommentBody.required' => 'Say something first.',
        ]);

        $comment->update(['body' => $this->editCommentBody]);

        $this->cancelEditComment();
    }

    public function deleteComment(int $commentId): void
    {
        $epic = $this->teamEpic($this->openEpicId);
        $comment = $epic->comments()->findOr($commentId, fn () => abort(403));

        $this->authorize('delete', $comment);

        // The DB cascade sweeps the thread's replies along with the root.
        $comment->delete();

        if ($this->editingCommentId === $commentId) {
            $this->cancelEditComment();
        }
        if ($this->replyingToId === $commentId) {
            $this->replyingToId = null;
        }
    }

    // ----------------------------------------------------------------- shared

    private function teamEpic(?int $epicId): Epic
    {
        abort_if($epicId === null, 404);

        return Epic::where('team_id', Auth::user()->currentTeam->id)
            ->findOr($epicId, fn () => abort(403));
    }

    private function teamStatus(?int $statusId): Status
    {
        abort_if($statusId === null, 404);

        return Status::where('team_id', Auth::user()->currentTeam->id)
            ->findOr($statusId, fn () => abort(403));
    }

    private function clearFrom(Epic $epic, \DateTimeInterface $week, ?int $engineerId = null): void
    {
        Allocation::where('epic_id', $epic->id)
            ->where('week_start', '>=', $week->format('Y-m-d'))
            ->when($engineerId, fn ($q) => $q->where('engineer_id', $engineerId))
            ->delete();
    }

    /**
     * The week work actually stopped.
     *
     * A recorded pause wins because a person put that date there; otherwise it
     * is derived by counting the silent weeks back from now.
     */
    private function pausedSince(int $quietWeeks): \Carbon\CarbonImmutable
    {
        return CapacityService::for(Auth::user()->currentTeam)
            ->currentWeek()
            ->subWeeks(max(0, $quietWeeks - 1));
    }

    public function with(): array
    {
        $team = Auth::user()->currentTeam;
        $capacity = CapacityService::for($team);
        $quarter = Quarter::current();
        $week = $capacity->currentWeek();

        $statuses = $team->statuses()->ordered()->get();

        // A remembered hidden column can outlive its status. Forget it, so
        // the count on the filter button never claims a ghost.
        $this->hiddenColumns = array_values(array_intersect(
            $this->hiddenColumns,
            $statuses->pluck('id')->map(fn ($id) => (string) $id)->all(),
        ));

        $epics = $team->epics()
            ->with(['category', 'status', 'quarterPlans.squad', 'pauses.supersededBy'])
            ->onBoard()
            ->get();

        $staffed = $capacity->staffedEpicIds();

        // Who is on what this week, in one query.
        $thisWeek = Allocation::inWeek($week)
            ->whereHas('engineer', fn ($q) => $q->where('team_id', $team->id))
            ->with('engineer.squad')
            ->get()
            ->groupBy('epic_id');

        $epics->each(function ($epic) use ($staffed, $quarter, $thisWeek) {
            $epic->isStaffed = $staffed->contains($epic->id);
            $epic->crew = ($thisWeek[$epic->id] ?? collect())->pluck('engineer')->filter();

            // The squad owning this quarter's plan. Cards carry it as their
            // left edge, which survives every density.
            $epic->squad = $epic->quarterPlans->first()?->squad;
            $epic->flag = null;

            $status = $epic->status;

            // A pause only counts while the column still says "stopped". A
            // record left open after the card moved on is history, not the
            // present, so it never surfaces here.
            $epic->openPause = $status?->requires_reason
                ? $epic->pauses->whereNull('resumed_at')->sortByDesc('paused_at')->first()
                : null;

            if ($status?->is_complete && $epic->isStaffed) {
                // Filed as finished, yet people are still booked on it.
                $epic->flag = ['tone' => 'blue', 'label' => 'Still booked'];
            }
        });

        // A remembered filter can outlive its squad -- deleted, or from
        // another team. Fall back to everything rather than an empty board.
        if (! in_array($this->squadFilter, ['', 'none'], true)
            && ! $team->squads()->whereKey($this->squadFilter)->exists()) {
            $this->squadFilter = '';
        }

        $visible = $epics->filter(fn ($epic) => $this->passesSquadFilter($epic));

        $byStatus = $visible->groupBy('status_id');

        $columns = $statuses
            ->reject(fn ($status) => in_array((string) $status->id, $this->hiddenColumns, true))
            ->values()
            ->map(fn ($status) => [
                'status' => $status,
                'epics' => $byStatus[$status->id] ?? collect(),
            ]);

        return [
            'columns' => $columns,
            'statuses' => $statuses,
            'filterCount' => ($this->squadFilter !== '' ? 1 : 0) + count($this->hiddenColumns),
            'unfiled' => $visible->whereNull('status_id')->values(),
            'weekLabel' => $week->format('M j'),
            'quarterLabel' => $quarter->label(),
            'candidateEpics' => $epics->sortBy('title')->values(),
            'categories' => $team->categories()->ordered()->get(),
            'squads' => $team->squads()->ordered()->get(),
            ...$this->flyoutData($epics, $week),
        ];
    }

    /**
     * Everything the flyout needs. Split out so with() stays readable and the
     * work is skipped entirely while the flyout is closed.
     */
    private function flyoutData($epics, $week): array
    {
        $epic = $this->openEpicId ? $epics->firstWhere('id', $this->openEpicId) : null;

        if (! $epic) {
            return [
                'openEpic' => null,
                'available' => collect(),
                'openCrew' => collect(),
                'openPlan' => null,
                'openStaffedPoints' => 0,
                'openComments' => collect(),
                'openReplies' => collect(),
                'openCommentCount' => 0,
            ];
        }

        // Comments load only here, not in the board query -- the board never
        // shows them, so the cost is paid only while the flyout is open.
        $epic->load('comments.user');

        $openCrew = Allocation::where('epic_id', $epic->id)
            ->where('week_start', '>=', $week->toDateString())
            ->with('engineer.squad')
            ->get()
            ->groupBy('engineer_id')
            ->map(fn ($rows) => [
                'engineer' => $rows->first()->engineer,
                'weeks' => $rows->count(),
            ])
            ->filter(fn ($row) => $row['engineer'] !== null)
            ->values();

        $crewIds = $openCrew->map(fn ($row) => $row['engineer']->id);

        $quarter = Quarter::current();

        return [
            'openEpic' => $epic,
            'openCrew' => $openCrew,
            'openPlan' => $epic->quarterPlans
                ->first(fn ($plan) => $plan->year === $quarter->year && $plan->quarter === $quarter->quarter),
            'openStaffedPoints' => CapacityService::for(Auth::user()->currentTeam)->epicQuarterPoints($epic, $quarter),
            'available' => Auth::user()->currentTeam->engineers()
                ->with('squad')->active()->ordered()->get()
                ->reject(fn ($engineer) => $crewIds->contains($engineer->id))
                ->values(),
            'openComments' => $epic->comments->whereNull('parent_id')->sortBy('created_at')->values(),
            'openReplies' => $epic->comments->whereNotNull('parent_id')->sortBy('created_at')->groupBy('parent_id'),
            'openCommentCount' => $epic->comments->count(),
        ];
    }
};
?>

@php
    $micro = 'text-[10px] font-semibold uppercase tracking-[0.14em] text-zinc-400 dark:text-zinc-500';

    $toneClasses = [
        'amber' => 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
        'orange' => 'bg-orange-100 text-orange-800 dark:bg-orange-950/60 dark:text-orange-300',
        'red' => 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300',
        'blue' => 'bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300',
    ];

    // Compact cards have no room for the flag's label, so it shrinks to a dot
    // that keeps the same colour meaning.
    $flagDot = [
        'amber' => 'bg-amber-500',
        'orange' => 'bg-orange-500',
        'red' => 'bg-red-500',
        'blue' => 'bg-blue-500',
    ];

    // Narrower columns when cards are thin, wider when they carry a description.
    $columnWidth = match ($density) {
        'compact' => 'w-[17rem]',
        'detailed' => 'w-[21rem]',
        default => 'w-[19rem]',
    };
@endphp

<div>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6">
        <div>
            <h1>Now</h1>
            <flux:text class="mt-1">Week of {{ $weekLabel }} · {{ $quarterLabel }}</flux:text>
        </div>
        <div class="flex items-center gap-2">
            {{-- Everything that narrows the board lives behind one control:
                 which squad's cards, and which columns get drawn. The badge
                 says how much of the board you are not seeing. --}}
            <flux:dropdown position="bottom" align="end">
                <flux:button size="sm" icon="funnel" icon:variant="micro">
                    Filter
                    @if($filterCount > 0)
                    <span class="inline-grid place-items-center align-middle min-w-[18px] h-[18px] px-1 rounded-full
                                 bg-zinc-900 text-white dark:bg-white dark:text-zinc-900
                                 text-[10px] font-semibold tabular-nums">{{ $filterCount }}</span>
                    @endif
                </flux:button>

                <flux:menu class="w-60">
                    @if($squads->isNotEmpty())
                    <flux:menu.group heading="Squad">
                        <flux:menu.radio.group wire:model.live="squadFilter">
                            <flux:menu.radio value="">All squads</flux:menu.radio>
                            @foreach($squads as $squad)
                            <flux:menu.radio value="{{ $squad->id }}">{{ $squad->name }}</flux:menu.radio>
                            @endforeach
                            <flux:menu.radio value="none">No squad</flux:menu.radio>
                        </flux:menu.radio.group>
                    </flux:menu.group>

                    <flux:menu.separator />
                    @endif

                    {{-- Checked means drawn. The dots match the column
                         headers, so the list reads as a map of the board. --}}
                    <flux:menu.group heading="Columns">
                        @foreach($statuses as $status)
                        <flux:menu.checkbox wire:click="toggleColumn({{ $status->id }})"
                                            :checked="! in_array((string) $status->id, $hiddenColumns, true)">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="size-2 rounded-full shrink-0" style="background-color: {{ $status->color }}"></span>
                                <span class="truncate">{{ $status->name }}</span>
                            </div>
                        </flux:menu.checkbox>
                        @endforeach
                    </flux:menu.group>

                    <flux:menu.separator />

                    <flux:menu.item icon="adjustments-horizontal" href="/statuses" wire:navigate>
                        Edit columns
                    </flux:menu.item>
                </flux:menu>
            </flux:dropdown>

            {{-- How much a card shows. Sticks per user, because the right
                 answer depends on how many epics you are carrying. --}}
            <div class="flex items-center rounded-lg border border-zinc-200 dark:border-zinc-700 p-0.5"
                 role="group" aria-label="Card density">
                @foreach(['compact' => 'bars-2', 'standard' => 'bars-3', 'detailed' => 'bars-4'] as $option => $icon)
                <button type="button" wire:click="setDensity('{{ $option }}')"
                        aria-pressed="{{ $density === $option ? 'true' : 'false' }}"
                        title="{{ ucfirst($option) }} cards"
                        class="grid place-items-center size-7 rounded-md transition-colors
                               {{ $density === $option
                                  ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900'
                                  : 'text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200' }}">
                    <flux:icon :icon="$icon" variant="micro" class="size-4" />
                    <span class="sr-only">{{ ucfirst($option) }} cards</span>
                </button>
                @endforeach
            </div>

            <flux:button size="sm" variant="primary" icon="plus" wire:click="newEpic">New epic</flux:button>
        </div>
    </div>

    @if($statuses->isEmpty())
    <flux:card>
        <div class="text-center py-12">
            <flux:icon.view-columns class="mx-auto h-12 w-12 text-zinc-400" />
            <flux:heading size="lg" class="mt-4">No columns yet</flux:heading>
            <flux:text class="mt-2">Set up the statuses your team works in and this board fills itself.</flux:text>
            <flux:button href="/statuses" variant="primary" class="mt-6" wire:navigate>Set up statuses</flux:button>
        </div>
    </flux:card>
    @elseif($columns->isEmpty())
    {{-- The filter can hide the whole board. Say so, and hand back the way out. --}}
    <flux:card>
        <div class="text-center py-12">
            <flux:icon.eye-slash class="mx-auto h-12 w-12 text-zinc-400" />
            <flux:heading size="lg" class="mt-4">All columns are hidden</flux:heading>
            <flux:text class="mt-2">The filter is hiding every column on the board.</flux:text>
            <flux:button variant="primary" class="mt-6" wire:click="showAllColumns">Show all columns</flux:button>
        </div>
    </flux:card>
    @else

    {{-- Columns scroll sideways rather than shrinking: a card that has been
         squeezed to nothing tells you less than one you have to scroll to. --}}
    <div class="overflow-x-auto [contain:paint] -mx-1 px-1 pb-2">
        <div class="flex gap-3 items-start min-w-max">
            @foreach($columns as $column)
            @php $status = $column['status']; @endphp

            <section class="{{ $columnWidth }} shrink-0 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50/60 dark:bg-zinc-900/50"
                     wire:key="column-{{ $status->id }}">

                <header class="px-3 pt-3 pb-2.5 border-b border-zinc-200 dark:border-zinc-700">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="size-2.5 rounded-full shrink-0" style="background-color: {{ $status->color }}"></span>
                        <h2 class="text-[13px] font-semibold truncate text-zinc-800 dark:text-zinc-200 flex-1">
                            {{ $status->name }}
                        </h2>
                        <span class="text-[11px] tabular-nums font-medium text-zinc-400 shrink-0">
                            {{ $column['epics']->count() }}
                        </span>
                    </div>

                </header>

                {{-- An empty drop area takes no room -- the Add epic button
                     below is the empty state, and reserving blank space above
                     it just looks broken. emptyInsertThreshold is what keeps
                     the column droppable anyway: a drag within that many
                     pixels of the collapsed area gets pulled in. --}}
                {{-- forceFallback swaps the browser's washed-out native drag
                     image for a real clone we can style (see app.css), and the
                     tolerance means a press has to travel a few pixels before
                     it becomes a drag -- clicks stay clicks. --}}
                <div class="p-2 space-y-2"
                     x-sort.ghost="$wire.moveEpic($item, $position, {{ $status->id }})"
                     x-sort:group="board"
                     {{-- fallbackOnBody: the clone is position:fixed, and the
                          board's contain:paint wrapper would otherwise become
                          its containing block and drag it far from the cursor. --}}
                     x-sort:config="{ forceFallback: true, fallbackTolerance: 5, fallbackOnBody: true, emptyInsertThreshold: 64 }">

                    @foreach($column['epics'] as $epic)
                    @php
                        $squadColor = $epic->squad->color ?? '#a1a1aa';
                        $faces = $density === 'compact' ? 3 : 5;
                        $faceSize = $density === 'compact' ? 'size-5' : 'size-6';
                    @endphp

                    {{-- The left edge is the squad, at every density. It costs no
                         horizontal room, so the one fact that would otherwise be
                         cut from a compact card survives. --}}
                    {{-- The whole card opens the flyout. In fallback mode the
                         sort plugin does NOT swallow the click that fires when
                         a drag is released, so the card measures for itself:
                         a "click" whose pointer travelled since pressing was a
                         drop, not a click. The 5px line matches
                         fallbackTolerance above -- below it nothing dragged,
                         at it the press became a drag. Keyboard activation
                         (detail 0) always opens. --}}
                    <article x-sort:item="{{ $epic->id }}" wire:key="card-{{ $epic->id }}"
                             x-data="{ downX: 0, downY: 0 }"
                             x-on:pointerdown="downX = $event.clientX; downY = $event.clientY"
                             x-on:click="($event.detail === 0 || Math.hypot($event.clientX - downX, $event.clientY - downY) < 5) && $wire.open({{ $epic->id }})"
                             class="group relative overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900
                                    cursor-pointer select-none hover:border-zinc-300 dark:hover:border-zinc-600 transition-colors
                                    {{ $density === 'compact' ? 'pl-3 pr-2.5 py-2' : 'pl-3.5 pr-3 py-2.5' }}">

                        <span class="absolute inset-y-0 left-0 w-1" style="background-color: {{ $squadColor }}"
                              @if($epic->squad) title="{{ $epic->squad->name }}" @endif></span>

                        @if($density === 'compact')
                        {{-- One line. Title and faces, nothing else. --}}
                        <div class="flex items-center gap-2">
                            @if($epic->flag)
                            <span class="size-1.5 rounded-full shrink-0 {{ $flagDot[$epic->flag['tone']] }}"
                                  title="{{ $epic->flag['label'] }}"></span>
                            @endif

                            {{-- No handler of its own: the click bubbles to the
                                 card's drag-aware one. The button stays for
                                 focus and keyboard reach. --}}
                            <button type="button"
                                    class="flex-1 min-w-0 text-left text-[13px] font-medium leading-snug truncate text-zinc-900 dark:text-zinc-100 cursor-pointer">
                                {{ $epic->title }}
                            </button>

                            @if($epic->crew->isNotEmpty())
                            <div class="flex -space-x-1.5 shrink-0">
                                @foreach($epic->crew->take($faces) as $engineer)
                                <x-engineer-avatar :engineer="$engineer" :size="$density === 'compact' ? 'xs' : 'sm'"
                                                   class="ring-2 ring-white dark:ring-zinc-900" />
                                @endforeach
                                @if($epic->crew->count() > $faces)
                                <flux:avatar circle :size="$density === 'compact' ? 'xs' : 'sm'"
                                             class="ring-2 ring-white dark:ring-zinc-900"
                                             :tooltip="$epic->crew->skip($faces)->pluck('name')->implode(', ')">
                                    +{{ $epic->crew->count() - $faces }}
                                </flux:avatar>
                                @endif
                            </div>
                            @endif
                        </div>

                        @else
                        {{-- No handler of its own: the click bubbles to the
                             card's drag-aware one. The button stays for focus
                             and keyboard reach. --}}
                        <button type="button"
                                class="block w-full text-left text-[13px] font-medium leading-snug text-zinc-900 dark:text-zinc-100 cursor-pointer">
                            {{ $epic->title }}
                        </button>

                        @if($density === 'detailed' && filled($epic->description))
                        <p class="mt-1.5 text-[11px] leading-relaxed text-zinc-500 dark:text-zinc-400 line-clamp-2">
                            {{ $epic->description }}
                        </p>
                        @endif

                        <div class="flex flex-wrap items-center gap-1.5 mt-1.5">
                            @if($epic->squad)
                            <span class="inline-flex items-center gap-1 text-[10px] px-1.5 py-0.5 rounded font-medium"
                                  style="background-color: {{ $squadColor }}1f; color: {{ $squadColor }}">
                                <span class="size-1.5 rounded-full" style="background-color: {{ $squadColor }}"></span>
                                {{ $epic->squad->name }}
                            </span>
                            @endif

                            @if($epic->category)
                            <span class="text-[10px] px-1.5 py-0.5 rounded font-medium"
                                  style="background-color: {{ $epic->category->color }}20; color: {{ $epic->category->color }}">
                                {{ $epic->category->name }}
                            </span>
                            @endif

                            <x-priority-icon :priority="$epic->priority" />

                            {{-- click.stop inside the chip keeps a jump to
                                 Jira from also opening the flyout. --}}
                            @if($epic->jira_epic_url)
                            <x-atlassian-link :url="$epic->jira_epic_url" kind="jira" />
                            @endif
                            @if($epic->jpd_idea_url)
                            <x-atlassian-link :url="$epic->jpd_idea_url" kind="idea" />
                            @endif
                        </div>

                        {{-- Who is actually on it this week -- the one fact a
                             manual column cannot fake. --}}
                        <div class="mt-2.5">
                            @if($epic->crew->isEmpty())
                            <span class="text-[11px] text-zinc-400">No one assigned</span>

                            @elseif($density === 'detailed')
                            <div class="flex flex-wrap gap-1">
                                @foreach($epic->crew as $engineer)
                                <span class="inline-flex items-center gap-1.5 pl-0.5 pr-2 py-0.5 rounded-full bg-zinc-100 dark:bg-zinc-800">
                                    <x-engineer-avatar :engineer="$engineer" size="xs" :tooltip="false" />
                                    <span class="text-[11px] text-zinc-600 dark:text-zinc-300">{{ $engineer->name }}</span>
                                </span>
                                @endforeach
                            </div>

                            @else
                            <div class="flex -space-x-1.5">
                                @foreach($epic->crew->take($faces) as $engineer)
                                <x-engineer-avatar :engineer="$engineer" :size="$density === 'compact' ? 'xs' : 'sm'"
                                                   class="ring-2 ring-white dark:ring-zinc-900" />
                                @endforeach
                                @if($epic->crew->count() > $faces)
                                <flux:avatar circle :size="$density === 'compact' ? 'xs' : 'sm'"
                                             class="ring-2 ring-white dark:ring-zinc-900"
                                             :tooltip="$epic->crew->skip($faces)->pluck('name')->implode(', ')">
                                    +{{ $epic->crew->count() - $faces }}
                                </flux:avatar>
                                @endif
                            </div>
                            @endif
                        </div>

                        @if($epic->flag)
                        <div class="mt-2 pt-2 border-t border-zinc-100 dark:border-zinc-800">
                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold
                                         {{ $toneClasses[$epic->flag['tone']] }}">
                                <flux:icon.exclamation-circle variant="micro" class="size-3" />
                                {{ $epic->flag['label'] }}
                            </span>
                        </div>
                        @endif
                        @endif
                    </article>
                    @endforeach
                </div>

                {{-- A card-shaped invitation at the foot of every column. It
                     lives outside the drop zone so a drag never mistakes it
                     for a slot; the flyout opens with this status (and any
                     squad filter) already picked. --}}
                <div class="px-2 pb-2">
                    <button type="button" wire:click="newEpic({{ $status->id }})"
                            class="flex w-full items-center justify-center gap-1.5 rounded-lg border border-dashed border-zinc-300 dark:border-zinc-700
                                   {{ $density === 'compact' ? 'py-2' : 'py-2.5' }}
                                   text-[13px] font-medium text-zinc-400 dark:text-zinc-500 cursor-pointer transition-colors
                                   hover:border-zinc-400 dark:hover:border-zinc-500 hover:text-zinc-600 dark:hover:text-zinc-300
                                   hover:bg-zinc-100/60 dark:hover:bg-zinc-800/40">
                        <flux:icon.plus variant="micro" />
                        Add epic
                    </button>
                </div>
            </section>
            @endforeach

            {{-- Only appears if a status was deleted without reassigning. --}}
            @if($unfiled->isNotEmpty())
            <section class="w-[19rem] shrink-0 rounded-xl border border-dashed border-zinc-300 dark:border-zinc-600 p-3">
                <div class="{{ $micro }} mb-2">No status</div>
                <div class="space-y-2">
                    @foreach($unfiled as $epic)
                    <button type="button" wire:click="open({{ $epic->id }})" wire:key="unfiled-{{ $epic->id }}"
                            class="block w-full text-left rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2.5 text-[13px] font-medium hover:underline">
                        {{ $epic->title }}
                    </button>
                    @endforeach
                </div>
            </section>
            @endif
        </div>
    </div>
    @endif

    {{-- Detail flyout. Pausing has one door: land the epic in a status that
         asks why (chip or drag) and the pause form opens, records the reason
         and clears upcoming bookings. --}}
    {{-- A hard width, not a min: the dialog element sizes to fit its content,
         so anything one-line-long (a pause note, a wide grid) would otherwise
         drag the panel across the screen. --}}
    <flux:modal variant="flyout" wire:model="showFlyout" class="w-full max-w-md! p-6!">
        @if($creating)
        <form wire:submit="createEpic" class="space-y-6">
            <div>
                <flux:heading size="lg">New epic</flux:heading>
                <flux:text class="mt-1">Capture it now, fill in the detail later.</flux:text>
            </div>

            <flux:input wire:model="newTitle" label="Name" placeholder="Smart Charging Scheduler" autofocus />

            <div class="grid grid-cols-2 gap-3">
                {{-- The dot matches the column header, so the list reads as
                     a map of the board. --}}
                <flux:select variant="listbox" wire:model="newStatusId" label="Status">
                    @foreach($statuses as $status)
                    <flux:select.option value="{{ $status->id }}">
                        <div class="flex items-center gap-2">
                            <span class="size-2.5 rounded-full shrink-0" style="background-color: {{ $status->color }}"></span>
                            {{ $status->name }}
                        </div>
                    </flux:select.option>
                    @endforeach
                </flux:select>

                {{-- Same glyphs and colours as x-priority-icon, inlined: that
                     component carries a tooltip, which has no place inside a
                     listbox option. --}}
                <flux:select variant="listbox" wire:model="newPriority" label="Priority">
                    <flux:select.option value="low">
                        <div class="flex items-center gap-2"><flux:icon.chevron-down variant="micro" class="text-blue-600 dark:text-blue-400" /> Low</div>
                    </flux:select.option>
                    <flux:select.option value="medium">
                        <div class="flex items-center gap-2"><flux:icon.equal variant="micro" class="text-amber-600 dark:text-amber-400" /> Medium</div>
                    </flux:select.option>
                    <flux:select.option value="high">
                        <div class="flex items-center gap-2"><flux:icon.chevron-up variant="micro" class="text-orange-600 dark:text-orange-400" /> High</div>
                    </flux:select.option>
                    <flux:select.option value="critical">
                        <div class="flex items-center gap-2"><flux:icon.chevrons-up variant="micro" class="text-red-600 dark:text-red-400" /> Critical</div>
                    </flux:select.option>
                </flux:select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <flux:select variant="listbox" wire:model="newCategoryId" label="Category">
                    <flux:select.option value="">None</flux:select.option>
                    @foreach($categories as $category)
                    <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select variant="listbox" wire:model="newSquadId" label="Squad">
                    <flux:select.option value="">Unassigned</flux:select.option>
                    @foreach($squads as $squad)
                    <flux:select.option value="{{ $squad->id }}">{{ $squad->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:input type="number" min="0" wire:model="newPlannedPoints" label="Estimate" placeholder="Points" />

            <div class="flex justify-end gap-2">
                <flux:button type="button" size="sm" variant="ghost" wire:click="close">Cancel</flux:button>
                <flux:button type="submit" size="sm" variant="primary">Create epic</flux:button>
            </div>
        </form>
        @elseif($openEpic)
        {{-- min-height is the viewport minus the modal's p-6, so mt-auto can
             pin the move rail to the bottom edge even when content is short.
             (min-h-full has nothing to resolve against inside the dialog.) --}}
        <div class="flex min-h-[calc(100dvh-3rem)] flex-col">
            {{-- Identity. Every field here writes as it changes; the full page
                 is only needed for dates and the week spine. The close button
                 owns the top-right corner, so the badge row leaves it alone. --}}
            <div class="pr-8">
                <div class="flex items-center gap-2">
                    @if($openEpic->status)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold shrink-0"
                          style="background-color: {{ $openEpic->status->color }}1f; color: {{ $openEpic->status->color }}">
                        <span class="size-1.5 rounded-full" style="background-color: {{ $openEpic->status->color }}"></span>
                        {{ $openEpic->status->name }}
                    </span>
                    @endif
                    <span class="flex-1"></span>
                    <span class="text-[11px] font-medium text-zinc-400 shrink-0" wire:loading.delay
                          wire:target="editTitle, editCategoryId, editSquadId, editPriority, editDescription, editPlannedPoints, editJiraEpicUrl, editJpdIdeaUrl">Saving…</span>
                    <flux:button size="xs" variant="ghost" icon="arrow-top-right-on-square"
                                 href="/epics/{{ $openEpic->id }}/edit" wire:navigate>Full view</flux:button>
                </div>

                <input type="text" wire:model.live.debounce.600ms="editTitle" placeholder="Untitled epic"
                       aria-label="Epic title"
                       class="mt-3 w-full min-w-0 bg-transparent border-0 border-b border-transparent px-0 py-0.5
                              text-lg font-semibold text-zinc-900 dark:text-zinc-100
                              placeholder:text-zinc-300 dark:placeholder:text-zinc-600
                              hover:border-zinc-200 dark:hover:border-zinc-700
                              focus:border-accent focus:ring-0 focus:outline-none transition-colors" />
                <flux:error name="editTitle" />

                <textarea wire:model.live.debounce.800ms="editDescription" rows="2"
                          placeholder="Add a description…" aria-label="Epic description"
                          class="mt-1.5 w-full resize-none bg-transparent border-0 border-b border-transparent px-0 py-0.5
                                 text-sm leading-relaxed text-zinc-600 dark:text-zinc-300
                                 placeholder:text-zinc-300 dark:placeholder:text-zinc-600
                                 hover:border-zinc-200 dark:hover:border-zinc-700
                                 focus:border-accent focus:ring-0 focus:outline-none transition-colors"></textarea>
                <flux:error name="editDescription" />
            </div>

            {{-- Two rooms: facts and conversation never fight for one scroll. --}}
            <div class="mt-4 flex gap-1 border-b border-zinc-200 dark:border-zinc-700">
                <button type="button" wire:click="setFlyoutTab('details')"
                        class="-mb-px px-3 pt-1.5 pb-2.5 text-[13px] font-semibold border-b-2 transition-colors
                               {{ $flyoutTab === 'details'
                                  ? 'text-zinc-900 dark:text-zinc-100 border-zinc-900 dark:border-zinc-100'
                                  : 'text-zinc-400 dark:text-zinc-500 border-transparent hover:text-zinc-600 dark:hover:text-zinc-300' }}">
                    Details
                </button>
                <button type="button" wire:click="setFlyoutTab('comments')"
                        class="-mb-px px-3 pt-1.5 pb-2.5 text-[13px] font-semibold border-b-2 transition-colors inline-flex items-center gap-1.5
                               {{ $flyoutTab === 'comments'
                                  ? 'text-zinc-900 dark:text-zinc-100 border-zinc-900 dark:border-zinc-100'
                                  : 'text-zinc-400 dark:text-zinc-500 border-transparent hover:text-zinc-600 dark:hover:text-zinc-300' }}">
                    Comments
                    @if($openCommentCount > 0)
                    <span class="text-[10.5px] font-bold px-1.5 py-px rounded-full bg-zinc-100 dark:bg-zinc-700 text-zinc-500 dark:text-zinc-300">{{ $openCommentCount }}</span>
                    @endif
                </button>
            </div>

            @if($flyoutTab === 'details')
            <div class="pt-5 space-y-5">

            {{-- The facts, editable in place --}}
            <div class="grid grid-cols-2 gap-3">
                <flux:select variant="listbox" wire:model.live="editCategoryId" label="Category" size="sm">
                    <flux:select.option value="">None</flux:select.option>
                    @foreach($categories as $category)
                    <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select variant="listbox" wire:model.live="editSquadId" label="Squad" size="sm">
                    <flux:select.option value="">Unassigned</flux:select.option>
                    @foreach($squads as $squadOption)
                    <flux:select.option value="{{ $squadOption->id }}">{{ $squadOption->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select variant="listbox" wire:model.live="editPriority" label="Priority" size="sm">
                    <flux:select.option value="low">
                        <div class="flex items-center gap-2"><flux:icon.chevron-down variant="micro" class="text-blue-600 dark:text-blue-400" /> Low</div>
                    </flux:select.option>
                    <flux:select.option value="medium">
                        <div class="flex items-center gap-2"><flux:icon.equal variant="micro" class="text-amber-600 dark:text-amber-400" /> Medium</div>
                    </flux:select.option>
                    <flux:select.option value="high">
                        <div class="flex items-center gap-2"><flux:icon.chevron-up variant="micro" class="text-orange-600 dark:text-orange-400" /> High</div>
                    </flux:select.option>
                    <flux:select.option value="critical">
                        <div class="flex items-center gap-2"><flux:icon.chevrons-up variant="micro" class="text-red-600 dark:text-red-400" /> Critical</div>
                    </flux:select.option>
                </flux:select>

                {{-- The capacity number, next to the reality it should match.
                     Points hang off this quarter's plan, so the field only
                     exists once a squad gives the plan somewhere to live. --}}
                @if($openPlan)
                <flux:field>
                    <flux:label>Planned · {{ $quarterLabel }}</flux:label>
                    <div class="flex items-center gap-2.5">
                        <flux:input type="number" min="0" size="sm" class="w-20"
                                    wire:model.live.debounce.600ms="editPlannedPoints" />
                        <flux:text class="text-xs whitespace-nowrap">{{ $openStaffedPoints }} staffed</flux:text>
                    </div>
                    <flux:error name="editPlannedPoints" />
                </flux:field>
                @endif
            </div>

            {{-- Where it lives in Atlassian. Paste the link and it saves like
                 every other field; the chip is the way out to the issue. --}}
            <div class="space-y-2">
                <div class="{{ $micro }}">Links</div>

                <div class="flex items-center gap-2">
                    <span class="w-16 shrink-0 text-xs text-zinc-500 dark:text-zinc-400">Jira epic</span>
                    <flux:input type="url" size="sm" class="flex-1" placeholder="https://…/browse/KEY-1"
                                wire:model.live.debounce.600ms="editJiraEpicUrl" />
                    @if($openEpic->jira_epic_url)
                    <x-atlassian-link :url="$openEpic->jira_epic_url" kind="jira" />
                    @endif
                </div>
                <flux:error name="editJiraEpicUrl" />

                <div class="flex items-center gap-2">
                    <span class="w-16 shrink-0 text-xs text-zinc-500 dark:text-zinc-400">JPD idea</span>
                    <flux:input type="url" size="sm" class="flex-1" placeholder="https://…?selectedIssue=KEY-1"
                                wire:model.live.debounce.600ms="editJpdIdeaUrl" />
                    @if($openEpic->jpd_idea_url)
                    <x-atlassian-link :url="$openEpic->jpd_idea_url" kind="idea" />
                    @endif
                </div>
                <flux:error name="editJpdIdeaUrl" />
            </div>

            {{-- Why it stopped --}}
            @if($openEpic->openPause)
            <div class="flex gap-2.5 rounded-lg border border-zinc-200 dark:border-zinc-700 px-3.5 py-3">
                <flux:icon.pause-circle variant="mini" class="size-4 mt-0.5 shrink-0 text-amber-500" />
                <flux:text class="text-sm">
                    <span class="font-medium text-zinc-800 dark:text-zinc-200">Paused {{ $openEpic->openPause->paused_at->format('M j') }}.</span>
                    {{ $openEpic->openPause->reason }}
                    @if($openEpic->openPause->supersededBy)
                        Capacity went to <span class="font-medium">{{ $openEpic->openPause->supersededBy->title }}</span>.
                    @endif
                </flux:text>
            </div>
            @endif

            {{-- People: who is on it. Adding someone books them from this week
                 through the end of the quarter in one click -- fine-grained
                 weeks live on the epic page, not here. --}}
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <div class="{{ $micro }}">People</div>
                    <div class="flex items-center gap-1.5">
                        @if($available->isNotEmpty())
                        <flux:dropdown position="bottom" align="end">
                            <flux:button size="xs" icon="plus" variant="filled">Add person</flux:button>

                            <flux:menu class="max-h-80 overflow-y-auto">
                                @foreach($available->groupBy(fn ($e) => $e->squad?->name ?? 'No squad') as $squadName => $people)
                                <flux:menu.group heading="{{ $squadName }}">
                                    @foreach($people as $person)
                                    <flux:menu.item wire:click="assign({{ $person->id }})">
                                        {{ $person->name }}
                                    </flux:menu.item>
                                    @endforeach
                                </flux:menu.group>
                                @endforeach
                            </flux:menu>
                        </flux:dropdown>
                        @endif
                    </div>
                </div>

            {{-- Pause work --}}
            @if($panel === 'pause')
            <form wire:submit="pauseWork" class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 space-y-4">
                <div>
                    <flux:heading size="base">Why is it stopping?</flux:heading>
                    <flux:text class="mt-0.5 text-xs">
                        Clears bookings from this week on. Past weeks stay — they are the record
                        of what was actually spent.
                    </flux:text>
                </div>

                <flux:input wire:model="pauseReason" label="Reason" size="sm"
                            placeholder="Deprioritised for the scheduler launch" />

                <flux:select variant="listbox" searchable clearable wire:model="supersededById" label="Capacity goes to" size="sm" placeholder="Nothing in particular">
                    @foreach($candidateEpics as $candidate)
                    @if($candidate->id !== $openEpicId)
                    <flux:select.option value="{{ $candidate->id }}">{{ $candidate->title }}</flux:select.option>
                    @endif
                    @endforeach
                </flux:select>

                <div class="flex justify-end gap-2">
                    <flux:button type="button" size="sm" variant="ghost" wire:click="showPanel(null)">Cancel</flux:button>
                    <flux:button type="submit" size="sm" variant="danger">Pause work</flux:button>
                </div>
            </form>
            @endif

                @if($openCrew->isEmpty())
                @if(! $panel)
                <flux:text class="text-sm">Nobody is booked on this yet. Add a person to put them on it.</flux:text>
                @endif
                @else
                <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach($openCrew as $row)
                    <div class="flex items-center gap-3 py-2">
                        <x-engineer-avatar :engineer="$row['engineer']" size="sm" :tooltip="false" />
                        <span class="flex-1 min-w-0">
                            <span class="block text-sm truncate">{{ $row['engineer']->name }}</span>
                            <span class="block text-[11px] text-zinc-500 dark:text-zinc-400">
                                {{ $row['weeks'] }} {{ Str::plural('week', $row['weeks']) }} ahead
                            </span>
                        </span>
                        <flux:button size="xs" variant="ghost" icon="x-mark"
                                     wire:click="unstaff({{ $row['engineer']->id }})"
                                     title="Take {{ $row['engineer']->name }} off from this week on" />
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
            </div>
            @else

            {{-- Comments: the part of the record that only reads as prose.
                 One level of threading -- replies sit under their root behind
                 a left rule, and replying to a reply joins the same thread. --}}
            <div class="pt-5 space-y-4">
                @if($openComments->isEmpty())
                <flux:text class="text-sm">Nothing said yet. Leave the first comment.</flux:text>
                @endif

                @foreach($openComments as $comment)
                <div wire:key="comment-{{ $comment->id }}" class="flex gap-2.5">
                    <flux:avatar circle size="xs" :name="$comment->user->name" :src="$comment->user->profile_photo_url" />
                    <div class="flex-1 min-w-0">
                        <div class="flex items-baseline gap-2">
                            <span class="text-[13px] font-medium truncate">{{ $comment->user->name }}</span>
                            <span class="text-[11px] text-zinc-400 dark:text-zinc-500 shrink-0">{{ $comment->created_at->diffForHumans() }}</span>
                            <span class="flex-1"></span>
                            <flux:button size="xs" variant="ghost" wire:click="replyTo({{ $comment->id }})">Reply</flux:button>
                            @if($comment->user_id === Auth::id())
                            <flux:button size="xs" variant="ghost" icon="pencil"
                                         wire:click="editComment({{ $comment->id }})" title="Edit comment" />
                            <flux:button size="xs" variant="ghost" icon="trash"
                                         wire:click="deleteComment({{ $comment->id }})"
                                         wire:confirm="Delete this comment{{ ($openReplies[$comment->id] ?? collect())->isNotEmpty() ? ' and its replies' : '' }}?"
                                         title="Delete comment" />
                            @endif
                        </div>

                        @if($editingCommentId === $comment->id)
                        <form wire:submit="updateComment" class="mt-1 space-y-2">
                            <flux:textarea wire:model="editCommentBody" rows="2" />
                            <flux:error name="editCommentBody" />
                            <div class="flex justify-end gap-2">
                                <flux:button type="button" size="xs" variant="ghost" wire:click="cancelEditComment">Cancel</flux:button>
                                <flux:button type="submit" size="xs" variant="filled">Save</flux:button>
                            </div>
                        </form>
                        @else
                        <div class="text-sm whitespace-pre-line">{{ $comment->body }}</div>
                        @endif

                        @foreach($openReplies[$comment->id] ?? [] as $reply)
                        <div wire:key="comment-{{ $reply->id }}"
                             class="flex gap-2.5 mt-2 ml-1 pl-3 border-l border-zinc-200 dark:border-zinc-700">
                            <flux:avatar circle size="xs" :name="$reply->user->name" :src="$reply->user->profile_photo_url" />
                            <div class="flex-1 min-w-0">
                                <div class="flex items-baseline gap-2">
                                    <span class="text-[13px] font-medium truncate">{{ $reply->user->name }}</span>
                                    <span class="text-[11px] text-zinc-400 dark:text-zinc-500 shrink-0">{{ $reply->created_at->diffForHumans() }}</span>
                                    <span class="flex-1"></span>
                                    {{-- A reply's Reply button passes its own id; addComment re-roots it. --}}
                                    <flux:button size="xs" variant="ghost" wire:click="replyTo({{ $reply->id }})">Reply</flux:button>
                                    @if($reply->user_id === Auth::id())
                                    <flux:button size="xs" variant="ghost" icon="pencil"
                                                 wire:click="editComment({{ $reply->id }})" title="Edit reply" />
                                    <flux:button size="xs" variant="ghost" icon="trash"
                                                 wire:click="deleteComment({{ $reply->id }})"
                                                 wire:confirm="Delete this reply?" title="Delete reply" />
                                    @endif
                                </div>

                                @if($editingCommentId === $reply->id)
                                <form wire:submit="updateComment" class="mt-1 space-y-2">
                                    <flux:textarea wire:model="editCommentBody" rows="2" />
                                    <flux:error name="editCommentBody" />
                                    <div class="flex justify-end gap-2">
                                        <flux:button type="button" size="xs" variant="ghost" wire:click="cancelEditComment">Cancel</flux:button>
                                        <flux:button type="submit" size="xs" variant="filled">Save</flux:button>
                                    </div>
                                </form>
                                @else
                                <div class="text-sm whitespace-pre-line">{{ $reply->body }}</div>
                                @endif
                            </div>
                        </div>
                        @endforeach

                        @if($replyingToId === $comment->id || ($openReplies[$comment->id] ?? collect())->contains('id', $replyingToId))
                        <form wire:submit="addComment" class="mt-2 ml-1 pl-3 border-l border-zinc-200 dark:border-zinc-700 space-y-2">
                            <flux:textarea wire:model="commentBody" rows="2" placeholder="Reply…" autofocus />
                            <flux:error name="commentBody" />
                            <div class="flex justify-end gap-2">
                                <flux:button type="button" size="xs" variant="ghost" wire:click="replyTo({{ $replyingToId }})">Cancel</flux:button>
                                <flux:button type="submit" size="xs" variant="filled">Reply</flux:button>
                            </div>
                        </form>
                        @endif
                    </div>
                </div>
                @endforeach

                @if($replyingToId === null)
                <form wire:submit="addComment" class="space-y-2">
                    <flux:textarea wire:model="commentBody" rows="2" placeholder="Leave a comment…" />
                    <flux:error name="commentBody" />
                    <div class="flex justify-end">
                        <flux:button type="submit" size="sm" variant="filled">Comment</flux:button>
                    </div>
                </form>
                @endif
            </div>
            @endif

            {{-- Moving is one click, pinned where it always is. The current
                 column already reads from the badge up top, so the rail only
                 offers the places the epic can go. --}}
            <div class="mt-auto pt-6 -mx-6 -mb-6">
                <div class="sticky bottom-0 border-t border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-6 py-3.5">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="{{ $micro }} mr-1.5">Move to</span>
                        @foreach($statuses as $option)
                        @continue($openEpic->status_id === $option->id)
                        <button type="button" wire:click="moveEpic({{ $openEpic->id }}, 0, {{ $option->id }})"
                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium border transition-colors
                                       border-zinc-200 dark:border-zinc-700 hover:border-zinc-400 dark:hover:border-zinc-500">
                            <span class="size-1.5 rounded-full" style="background-color: {{ $option->color }}"></span>
                            {{ $option->name }}
                        </button>
                        @endforeach
                    </div>
                    @error('status')<flux:text class="text-sm text-red-600 dark:text-red-400 mt-2">{{ $message }}</flux:text>@enderror
                </div>
            </div>
        </div>
        @endif
    </flux:modal>
</div>
