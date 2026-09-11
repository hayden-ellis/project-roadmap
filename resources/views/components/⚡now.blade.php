<?php

use App\Actions\Comments\PostComment;
use App\Actions\Comments\UpdateComment;
use App\Models\Allocation;
use App\Models\Epic;
use App\Models\EpicComment;
use App\Models\EpicPause;
use App\Models\EpicQuarterPlan;
use App\Models\Status;
use App\Notifications\EpicCommented;
use App\Notifications\EpicMentioned;
use App\Services\CapacityService;
use App\Support\ColumnOrder;
use App\Support\DefaultSquad;
use App\Support\Mentions;
use App\Support\Quarter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
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
new #[Layout('components.layouts.app.header')] class extends Component
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

    public function mount(): void
    {
        // A fresh session opens on the user's default squad. Any choice made
        // since -- including "all squads" -- is in the session and wins.
        if (! session()->exists('now.squad')) {
            $this->squadFilter = (string) (DefaultSquad::id(Auth::user(), Auth::user()->currentTeam) ?? '');
        }
    }

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
     * Column drag handler. The order is the user's own, laid over the team
     * order from /statuses -- see App\Support\ColumnOrder.
     *
     * The drop position counts columns on screen, and hidden ones are not.
     * So the moved column lands ahead of whichever visible column now sits
     * at that position, or at the end, and hidden columns keep their place
     * relative to their neighbours.
     */
    public function moveColumn(int $item, int $position): void
    {
        $moved = $this->teamStatus($item);
        $user = Auth::user();
        $team = $user->currentTeam;

        $ordered = $this->orderedStatuses()->pluck('id')->reject(fn ($id) => $id === $moved->id)->values();

        $anchor = $ordered
            ->reject(fn ($id) => in_array((string) $id, $this->hiddenColumns, true))
            ->values()
            ->get($position);

        $ids = $ordered->all();
        array_splice($ids, $anchor === null ? count($ids) : $ordered->search($anchor), 0, [$moved->id]);

        ColumnOrder::save($user, $team, $ids);
    }

    public function resetColumnOrder(): void
    {
        ColumnOrder::reset(Auth::user(), Auth::user()->currentTeam);
    }

    /** @return \Illuminate\Support\Collection<int, Status> */
    private function orderedStatuses(): \Illuminate\Support\Collection
    {
        $user = Auth::user();

        return ColumnOrder::apply($user, $user->currentTeam, $user->currentTeam->statuses()->ordered()->get());
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
        $this->resetForms();
        $this->syncEditFields($epic);

        // The thread is on screen as soon as the dialog is, so opening is
        // reading: it clears the card's "new for you" tint.
        $this->markThreadRead($epic->id);
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

        $this->refreshBoard();
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

    /** Marks this epic's mention and reply notifications read. */
    private function markThreadRead(int $epicId): void
    {
        $this->unreadCommentNotifications()
            ->filter(fn ($notification) => (int) ($notification->data['epic_id'] ?? 0) === $epicId)
            ->each->markAsRead();
    }

    /** Unread mention and reply notifications, the two kinds a comment sends. */
    private function unreadCommentNotifications(): Collection
    {
        return Auth::user()
            ->unreadNotifications()
            ->whereIn('type', [EpicCommented::class, EpicMentioned::class])
            ->get();
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

        $this->refreshBoard();
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

        $this->refreshBoard();
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

        $this->refreshBoard();
    }

    public function updatedEditPriority(): void
    {
        $epic = $this->teamEpic($this->openEpicId);
        $this->authorize('update', $epic);

        $this->validate(['editPriority' => 'required|in:low,medium,high,critical']);

        $epic->update(['priority' => $this->editPriority]);

        $this->refreshBoard();
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

        $this->refreshBoard();
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

        $this->refreshBoard();
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

        $this->refreshBoard();
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

        $this->refreshBoard();
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

        $this->refreshBoard();
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
        $this->refreshBoard();
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
        $this->refreshBoard();
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

        $this->refreshBoard();
    }

    /** Takes one person off from this week forward, leaving their past weeks intact. */
    public function unstaff(int $engineerId): void
    {
        $epic = $this->teamEpic($this->openEpicId);
        $this->authorize('update', $epic);

        $this->clearFrom($epic, CapacityService::for(Auth::user()->currentTeam)->currentWeek(), $engineerId);

        $this->refreshBoard();
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

        $parent = $this->replyingToId === null
            ? null
            : $epic->comments()->findOr($this->replyingToId, fn () => abort(403));

        app(PostComment::class)->handle($epic, Auth::user(), $this->commentBody, $parent);

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

        app(UpdateComment::class)->handle($comment, $this->editCommentBody);

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

    /**
     * What every render needs. Livewire runs with() again for each island it
     * renders, so nothing costly is built here: the board and the flyout
     * each pull their own data from a computed property, which only runs
     * when the part of the page that reads it is being drawn.
     */
    public function with(): array
    {
        return $this->shared;
    }

    /**
     * Columns and pick-lists, drawn by both the board and the flyout.
     * Computed so a full render, which visits with() twice (the page and
     * the flyout island), still queries once.
     */
    #[Computed]
    public function shared(): array
    {
        $team = Auth::user()->currentTeam;

        return [
            'statuses' => $this->orderedStatuses(),
            'categories' => $team->categories()->ordered()->get(),
            'squads' => $team->squads()->ordered()->get(),
            'weekLabel' => CapacityService::for($team)->currentWeek()->format('M j'),
            'quarterLabel' => Quarter::current()->label(),
        ];
    }

    /**
     * The board: every card sorted into its column, with the facts the grid
     * adds. Only the page body reads this, so opening a card -- which
     * renders just the flyout island -- never runs it.
     */
    #[Computed]
    public function board(): array
    {
        $team = Auth::user()->currentTeam;
        $capacity = CapacityService::for($team);
        $week = $capacity->currentWeek();

        $statuses = $this->shared['statuses'];

        // A remembered hidden column can outlive its status. Forget it, so
        // the count on the filter button never claims a ghost.
        $this->hiddenColumns = array_values(array_intersect(
            $this->hiddenColumns,
            $statuses->pluck('id')->map(fn ($id) => (string) $id)->all(),
        ));

        $epics = $team->epics()
            ->with(['category', 'status', 'quarterPlans.squad', 'pauses.supersededBy'])
            ->withCount('comments')
            ->onBoard()
            ->get();

        // Cards with conversation you have not caught up on yet: any unread
        // comment notification that points at the epic. Status changes are
        // news too, but not the kind a speech bubble should claim.
        $unreadComments = $this->unreadCommentNotifications()
            ->pluck('data.epic_id')
            ->filter()
            ->map(fn ($id) => (int) $id);

        $staffed = $capacity->staffedEpicIds();

        // Who is on what this week, in one query.
        $thisWeek = Allocation::inWeek($week)
            ->whereHas('engineer', fn ($q) => $q->where('team_id', $team->id))
            ->with('engineer.squad')
            ->get()
            ->groupBy('epic_id');

        $epics->each(function ($epic) use ($staffed, $thisWeek, $unreadComments) {
            $epic->isStaffed = $staffed->contains($epic->id);
            $epic->crew = ($thisWeek[$epic->id] ?? collect())->pluck('engineer')->filter();

            // The squad owning this quarter's plan. Cards carry it as their
            // left edge, which survives every density.
            $epic->squad = $epic->quarterPlans->first()?->squad;
            $epic->flag = null;
            $epic->unreadComments = $unreadComments->contains($epic->id);
            $epic->openPause = $this->openPauseFor($epic);

            if ($epic->status?->is_complete && $epic->isStaffed) {
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
            'unfiled' => $visible->whereNull('status_id')->values(),
            'filterCount' => ($this->squadFilter !== '' ? 1 : 0) + count($this->hiddenColumns),
            'customOrder' => ColumnOrder::isCustom(Auth::user(), $team),
        ];
    }

    /**
     * A pause only counts while the column still says "stopped". A record
     * left open after the card moved on is history, not the present, so it
     * never surfaces.
     */
    private function openPauseFor(Epic $epic): ?EpicPause
    {
        return $epic->status?->requires_reason
            ? $epic->pauses->whereNull('resumed_at')->sortByDesc('paused_at')->first()
            : null;
    }

    /**
     * Everything the flyout shows, and nothing the board does. Comments load
     * only here -- the board never shows them, so the cost is paid only
     * while the flyout is open.
     */
    #[Computed]
    public function flyout(): array
    {
        $team = Auth::user()->currentTeam;

        $epic = $this->openEpicId
            ? $team->epics()
                ->with(['status', 'pauses.supersededBy', 'comments.user', 'comments.mentions'])
                ->find($this->openEpicId)
            : null;

        if (! $epic) {
            return [
                'openEpic' => null,
                'available' => collect(),
                'openCrew' => collect(),
                'openComments' => collect(),
                'openReplies' => collect(),
                'openCommentCount' => 0,
                'mentionable' => collect(),
                'candidateEpics' => collect(),
            ];
        }

        $epic->openPause = $this->openPauseFor($epic);

        // Who is on it: anyone booked from this week on. The weeks
        // themselves are not shown here -- the epic page has them.
        $openCrew = Allocation::where('epic_id', $epic->id)
            ->where('week_start', '>=', $team->weekCalendar()->current()->toDateString())
            ->with('engineer.squad')
            ->get()
            ->pluck('engineer')
            ->filter()
            ->unique('id')
            ->values();

        $crewIds = $openCrew->pluck('id');

        return [
            'openEpic' => $epic,
            'openCrew' => $openCrew,
            'available' => $team->engineers()
                ->with('squad')->active()->ordered()->get()
                ->reject(fn ($engineer) => $crewIds->contains($engineer->id))
                ->values(),
            'openComments' => $epic->comments->whereNull('parent_id')->sortBy('created_at')->values(),
            'openReplies' => $epic->comments->whereNotNull('parent_id')->sortBy('created_at')->groupBy('parent_id'),
            'openCommentCount' => $epic->comments->count(),
            // Who the composer can @-mention: members with logins.
            'mentionable' => Mentions::choices($team),
            // Where a pause can send the capacity. Only the pause form lists it.
            'candidateEpics' => $this->panel === 'pause'
                ? $team->epics()->onBoard()->get()->sortBy('title')->values()
                : collect(),
        ];
    }

    /**
     * An action fired from inside the flyout re-renders the flyout island
     * and nothing else. When it changed what a card shows too, ask for the
     * whole page instead.
     */
    private function refreshBoard(): void
    {
        $this->skipIslandsRender();
    }
};
?>

@php
    // The board's own data: a computed property rather than part of with(),
    // because with() runs again for every island render, and opening the
    // flyout must not rebuild the board on the way.
    ['columns' => $columns, 'unfiled' => $unfiled, 'filterCount' => $filterCount, 'customOrder' => $customOrder] = $this->board;

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

{{-- Poll so the board follows the team without a reload. Livewire pauses this in background tabs. --}}
{{-- `opening` is set by whatever opens the panel and cleared by the content
     that arrives (see the keyed x-init inside the flyout). It lives on the
     root so the cards and the island can both reach it.

     `dragged` is how a card tells a click from a drop. The sort plugin runs
     in fallback mode, and the click that follows a release is not always
     swallowed, so the root watches the pointer itself: a press that travels
     5px (the plugin's own tolerance) before it lets go was a drag, and the
     click it leaves behind is ignored. Watched on the window, because the
     pointer is over the clone or the gap between columns for most of a
     drag, not over the card. --}}
<div wire:poll.30s x-data="{ opening: false, pressX: 0, pressY: 0, dragged: false }"
     x-on:pointerdown="pressX = $event.clientX; pressY = $event.clientY; dragged = false"
     x-on:pointermove.window="if ($event.buttons && ! dragged && Math.hypot($event.clientX - pressX, $event.clientY - pressY) >= 5) dragged = true">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6">
        <div>
            <h1>Now</h1>
            <flux:text class="mt-1">Week of {{ $weekLabel }} · {{ $quarterLabel }}</flux:text>
        </div>
        <div class="flex items-center gap-2">
            <livewire:default-squad :selected="ctype_digit($squadFilter) ? (int) $squadFilter : null" />

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

                    @if($customOrder)
                    {{-- Only offered once there is something to undo. --}}
                    <flux:menu.item icon="arrow-uturn-left" wire:click="resetColumnOrder">
                        Reset column order
                    </flux:menu.item>
                    @endif

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

            <flux:button size="sm" variant="primary" icon="plus" wire:island="flyout" wire:click="newEpic"
                         x-on:click="opening = true; $wire.showFlyout = true">New epic</flux:button>
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
        {{-- Columns drag too, by their header, into an order that is this
             user's alone (see moveColumn). A separate sort group from the
             cards, so a card can never be dropped between columns and a
             column never into a card list. Same fallback config as the cards,
             for the same contain:paint reason. --}}
        <div class="flex gap-3 items-start min-w-max"
             x-sort.ghost="$wire.moveColumn($item, $position)"
             x-sort:group="columns"
             x-sort:config="{ forceFallback: true, fallbackTolerance: 5, fallbackOnBody: true }">
            @foreach($columns as $column)
            @php $status = $column['status']; @endphp

            <section class="{{ $columnWidth }} shrink-0 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50/60 dark:bg-zinc-900/50"
                     x-sort:item="{{ $status->id }}"
                     wire:key="column-{{ $status->id }}">

                <header x-sort:handle
                        class="px-3 pt-3 pb-2.5 border-b border-zinc-200 dark:border-zinc-700 cursor-grab active:cursor-grabbing"
                        title="Drag to reorder columns">
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
                     it just looks broken. While a drag is live, app.css gives
                     a card-less zone a landing strip instead (data-drop-zone),
                     so it can still be dropped into. Not emptyInsertThreshold:
                     that pulls the card into any empty column within reach on
                     every mouse move and made it flicker across the gap. --}}
                {{-- forceFallback swaps the browser's washed-out native drag
                     image for a real clone we can style (see app.css), and the
                     tolerance means a press has to travel a few pixels before
                     it becomes a drag -- clicks stay clicks. --}}
                <div class="p-2 space-y-2" data-drop-zone
                     x-sort.ghost="$wire.moveEpic($item, $position, {{ $status->id }})"
                     x-sort:group="board"
                     {{-- fallbackOnBody: the clone is position:fixed, and the
                          board's contain:paint wrapper would otherwise become
                          its containing block and drag it far from the cursor. --}}
                     x-sort:config="{ forceFallback: true, fallbackTolerance: 5, fallbackOnBody: true }">

                    @foreach($column['epics'] as $epic)
                    @php
                        $squadColor = $epic->squad->color ?? '#a1a1aa';
                        $faces = $density === 'compact' ? 3 : 5;
                    @endphp

                    {{-- The left edge is the squad, at every density. It costs no
                         horizontal room, so the one fact that would otherwise be
                         cut from a compact card survives. --}}
                    {{-- The whole card opens the flyout, unless the press
                         that ended here was a drag (see `dragged` on the
                         root). Keyboard activation (detail 0) always opens:
                         the title inside is a button, so Enter on it lands
                         here. --}}
                    {{-- The panel slides out at once, onto a placeholder,
                         and the call is aimed at the flyout island so the
                         reply carries the flyout and not the whole board. --}}
                    <article x-sort:item="{{ $epic->id }}" wire:key="card-{{ $epic->id }}"
                             x-on:click="if ($event.detail === 0 || ! dragged) { opening = true; $wire.showFlyout = true; $wire.$island('flyout').open({{ $epic->id }}) }"
                             class="group relative overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900
                                    cursor-pointer select-none hover:border-zinc-300 dark:hover:border-zinc-600 transition-colors
                                    {{ $density === 'compact' ? 'pl-3 pr-2.5 py-2' : 'pl-3.5 pr-3 pt-1.5 pb-2.5' }}">

                        <span class="absolute inset-y-0 left-0 w-1" style="background-color: {{ $squadColor }}"
                              @if($epic->squad) title="{{ $epic->squad->name }}" @endif></span>

                        {{-- Every card in a column is the same height. Each
                             region below reserves its room whether or not it
                             has anything to say, and nothing is allowed to
                             wrap onto a second line except the title, which
                             is clamped and padded to exactly two. --}}

                        @if($density === 'compact')
                        {{-- One line. Title and faces, nothing else. The row
                             is as tall as a face even when there are none. --}}
                        <div class="flex items-center gap-2 min-h-6">
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

                            {{-- Compact has no room for a count that is
                                 merely true; it shows only when the thread
                                 has something new for you. --}}
                            @if($epic->unreadComments)
                            <x-card-comments :count="$epic->comments_count" unread />
                            @endif

                            @if($epic->crew->isNotEmpty())
                            <div class="flex -space-x-1.5 shrink-0">
                                @foreach($epic->crew->take($faces) as $engineer)
                                <x-engineer-avatar :engineer="$engineer" size="xs" class="ring-2 ring-white dark:ring-zinc-900" />
                                @endforeach
                                @if($epic->crew->count() > $faces)
                                <flux:avatar circle size="xs" class="ring-2 ring-white dark:ring-zinc-900"
                                             :tooltip="$epic->crew->skip($faces)->pluck('name')->implode(', ')">
                                    +{{ $epic->crew->count() - $faces }}
                                </flux:avatar>
                                @endif
                            </div>
                            @endif
                        </div>

                        @else
                        {{-- Header band: whose it is on the left; the Jira key,
                             priority and any flag on the right. The squad
                             name gives way first if the two sides meet. --}}
                        <div class="flex items-center justify-between gap-2 h-4">
                            @if($epic->squad)
                            <span class="inline-flex items-center gap-1.5 min-w-0 text-[10px] font-semibold" style="color: {{ $squadColor }}">
                                <span class="size-1.5 rounded-full shrink-0" style="background-color: {{ $squadColor }}"></span>
                                <span class="truncate">{{ $epic->squad->name }}</span>
                            </span>
                            @else
                            <span class="text-[10px] font-medium text-zinc-400 dark:text-zinc-500 truncate">No squad</span>
                            @endif

                            <span class="inline-flex items-center gap-1.5 shrink-0 h-4">
                                @if($epic->flag)
                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold {{ $toneClasses[$epic->flag['tone']] }}">
                                    <flux:icon.exclamation-circle variant="micro" class="size-3" />
                                    {{ $epic->flag['label'] }}
                                </span>
                                @endif

                                {{-- click.stop inside the chip keeps a jump to
                                     Jira from also opening the flyout. --}}
                                @if($epic->jira_epic_url)
                                <x-atlassian-link :url="$epic->jira_epic_url" kind="jira" />
                                @endif
                                @if($epic->jpd_idea_url)
                                <x-atlassian-link :url="$epic->jpd_idea_url" kind="idea" />
                                @endif

                                <x-priority-icon :priority="$epic->priority" />
                            </span>
                        </div>

                        {{-- Two lines, always: a one-line title leaves the
                             second line blank rather than pulling the footer
                             up. No handler of its own -- the click bubbles to
                             the card's drag-aware one. --}}
                        <button type="button" title="{{ $epic->title }}"
                                class="block w-full mt-0.5 text-left text-[13px] font-medium leading-tight line-clamp-2 min-h-[2.5em] text-zinc-900 dark:text-zinc-100 cursor-pointer">
                            {{ $epic->title }}
                        </button>

                        @if($density === 'detailed')
                        <p class="mt-1 text-[11px] leading-snug line-clamp-2 min-h-[2.75em]
                                  {{ filled($epic->description) ? 'text-zinc-500 dark:text-zinc-400' : 'italic text-zinc-400 dark:text-zinc-500' }}">
                            {{ filled($epic->description) ? $epic->description : 'No description' }}
                        </p>
                        @endif

                        {{-- Footer: who is actually on it this week on the
                             left -- the one fact a manual column cannot fake
                             -- and the category on the right, on its own so
                             it is never mistaken for the squad. --}}
                        <div class="mt-1.5 flex items-center justify-between gap-2 min-h-8">
                            @if($epic->crew->isEmpty())
                            <span class="inline-flex items-center gap-1.5 min-w-0 text-[11px] text-zinc-400 dark:text-zinc-500">
                                <span class="size-8 rounded-full border border-dashed border-zinc-300 dark:border-zinc-600 shrink-0"></span>
                                <span class="truncate">No one assigned</span>
                            </span>
                            @else
                            {{-- Faces only, at every density; the name is a
                                 hover away and the row never has to wrap. --}}
                            <div class="flex -space-x-1.5 min-w-0">
                                @foreach($epic->crew->take($faces) as $engineer)
                                <x-engineer-avatar :engineer="$engineer" size="sm" class="ring-2 ring-white dark:ring-zinc-900" />
                                @endforeach
                                @if($epic->crew->count() > $faces)
                                <flux:avatar circle size="sm" class="ring-2 ring-white dark:ring-zinc-900"
                                             :tooltip="$epic->crew->skip($faces)->pluck('name')->implode(', ')">
                                    +{{ $epic->crew->count() - $faces }}
                                </flux:avatar>
                                @endif
                            </div>
                            @endif

                            {{-- The tags cluster: how much has been said,
                                 then what kind of work it is. --}}
                            <span class="inline-flex items-center gap-2.5 shrink-0">
                                @if($epic->comments_count > 0 || $epic->unreadComments)
                                <x-card-comments :count="$epic->comments_count" :unread="$epic->unreadComments" />
                                @endif

                                @if($epic->category)
                                <span class="inline-flex items-center gap-1 text-[10px] font-medium" style="color: {{ $epic->category->color }}">
                                    <flux:icon.tag variant="micro" class="size-3" />
                                    {{ $epic->category->name }}
                                </span>
                                @else
                                <span class="inline-flex items-center gap-1 text-[10px] text-zinc-400 dark:text-zinc-500">
                                    <flux:icon.tag variant="micro" class="size-3" />
                                    No category
                                </span>
                                @endif
                            </span>
                        </div>
                        @endif
                    </article>
                    @endforeach
                </div>

                {{-- A card-shaped invitation at the foot of every column. It
                     lives outside the drop zone so a drag never mistakes it
                     for a slot; the flyout opens with this status (and any
                     squad filter) already picked. --}}
                <div class="px-2 pb-2">
                    <button type="button" wire:island="flyout" wire:click="newEpic({{ $status->id }})"
                            x-on:click="opening = true; $wire.showFlyout = true"
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
                    <button type="button" wire:island="flyout" wire:click="open({{ $epic->id }})" wire:key="unfiled-{{ $epic->id }}"
                            x-on:click="opening = true; $wire.showFlyout = true"
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

    {{-- The epic dialog. Pausing has one door: land the epic in a status
         that asks why (drag) and the pause form opens here, records the
         reason and clears upcoming bookings. --}}
    {{-- An island, so that anything done in here -- opening, typing, a
         comment, a pick -- re-renders this dialog and not the board behind
         it. Actions that change a card call refreshBoard() to opt back into
         the full page. `always` keeps it in every full render too, so a
         drag that lands in a "why?" column still opens the pause form. --}}
    @island('flyout', always: true)
    @php
        // An island compiles to a view of its own, so the page's @php block
        // is out of reach: the one style it shares is restated, and its data
        // comes from the flyout computed property (see with()).
        $micro = 'text-[10px] font-semibold uppercase tracking-[0.14em] text-zinc-400 dark:text-zinc-500';

        [
            'openEpic' => $openEpic, 'openCrew' => $openCrew, 'available' => $available,
            'openComments' => $openComments, 'openReplies' => $openReplies,
            'openCommentCount' => $openCommentCount, 'mentionable' => $mentionable,
            'candidateEpics' => $candidateEpics,
        ] = $this->flyout;

        // The dialog wears the squad's colour along its top edge, the way
        // the card wears it down its left. Read from the pick-list rather
        // than the epic so a squad change repaints without a second query.
        $openSquad = $openEpic && $editSquadId ? $squads->firstWhere('id', (int) $editSquadId) : null;
        $edgeColor = $openSquad->color ?? '#a1a1aa';

        // Same glyphs and colours as x-priority-icon, inlined: that component
        // carries a tooltip, which has no place inside a menu.
        $priorities = [
            'low' => ['Low', 'chevron-down', 'text-blue-600 dark:text-blue-400'],
            'medium' => ['Medium', 'equal', 'text-amber-600 dark:text-amber-400'],
            'high' => ['High', 'chevron-up', 'text-orange-600 dark:text-orange-400'],
            'critical' => ['Critical', 'chevrons-up', 'text-red-600 dark:text-red-400'],
        ];

        // Chip triggers share one look: a quiet outline pill, 28px tall.
        $chip = 'inline-flex items-center gap-1.5 h-7 px-2.5 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800
                 text-[13px] font-medium text-zinc-800 dark:text-zinc-100 whitespace-nowrap cursor-pointer
                 hover:border-zinc-300 dark:hover:border-zinc-600 transition-colors';
        $ghostChip = 'inline-flex items-center gap-1 h-7 px-2 rounded-lg text-[13px] font-medium text-zinc-500 dark:text-zinc-400 whitespace-nowrap cursor-pointer
                      hover:bg-zinc-100 dark:hover:bg-zinc-700/60 transition-colors';
    @endphp
    {{-- .live, because a plain wire:model only queues the close for the
         next request. Until then the server still thinks the last epic is
         open, so the next card would open onto it. --}}
    <flux:modal wire:model.live="showFlyout" class="w-full max-w-2xl! p-0! overflow-hidden!">
        {{-- Whatever was here last is hidden from the click until the next
             epic arrives, so the dialog never opens onto the previous one.
             wire:loading would not do: an open queued behind an in-flight
             close is not "loading" yet. The reset sits on the content
             itself, keyed, so it runs only when something new has landed
             -- an empty close render leaves the placeholder up. --}}
        <div x-show="!opening">
        @if($creating)
        <form wire:submit="createEpic" class="p-6 space-y-6" wire:key="flyout-new" x-init="opening = false">
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

                <flux:select variant="listbox" wire:model="newPriority" label="Priority">
                    @foreach($priorities as $value => [$label, $glyph, $color])
                    <flux:select.option value="{{ $value }}">
                        <div class="flex items-center gap-2"><flux:icon :icon="$glyph" variant="micro" class="{{ $color }}" /> {{ $label }}</div>
                    </flux:select.option>
                    @endforeach
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

            <div class="flex justify-end gap-2">
                <flux:button type="button" size="sm" variant="ghost" wire:click="close">Cancel</flux:button>
                <flux:button type="submit" size="sm" variant="primary">Create epic</flux:button>
            </div>
        </form>
        @elseif($openEpic)
        {{-- Header and composer stay put; only the middle scrolls. The cap
             is the viewport minus the dialog's own margin. --}}
        <div class="flex max-h-[calc(100dvh-4rem)] flex-col" wire:key="flyout-{{ $openEpic->id }}" x-init="opening = false">
            <span class="h-1.5 shrink-0" style="background-color: {{ $edgeColor }}"></span>

            {{-- Identity. Every field here writes as it changes. --}}
            <div class="px-7 pt-4">
                <div class="flex items-center gap-2 h-6">
                    @if($openSquad)
                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold" style="color: {{ $openSquad->color }}">
                        <span class="size-1.5 rounded-full" style="background-color: {{ $openSquad->color }}"></span>
                        {{ $openSquad->name }}
                    </span>
                    @else
                    <span class="text-xs font-medium text-zinc-400 dark:text-zinc-500">No squad</span>
                    @endif

                    <span class="flex-1"></span>

                    <span class="text-[11px] font-medium text-zinc-400 shrink-0" wire:loading.delay
                          wire:target="editTitle, editCategoryId, editSquadId, editPriority, editDescription, editJiraEpicUrl, editJpdIdeaUrl">Saving…</span>

                    {{-- Where it sits on the board. Read-only here: moving
                         is a drag on the board, the one way it happens. --}}
                    @if($openEpic->status)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold shrink-0"
                          style="background-color: {{ $openEpic->status->color }}1f; color: {{ $openEpic->status->color }}">
                        <span class="size-1.5 rounded-full" style="background-color: {{ $openEpic->status->color }}"></span>
                        {{ $openEpic->status->name }}
                    </span>
                    @endif

                    <flux:modal.close>
                        <flux:button size="xs" variant="ghost" icon="x-mark" aria-label="Close" class="-mr-2" />
                    </flux:modal.close>
                </div>

                <input type="text" wire:model.live.debounce.600ms="editTitle" placeholder="Untitled epic"
                       aria-label="Epic title"
                       class="mt-3 w-full min-w-0 bg-transparent border-0 border-b border-transparent px-0 py-0.5
                              text-[22px] font-semibold tracking-tight leading-tight text-zinc-900 dark:text-zinc-100
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

            <div class="min-h-0 flex-1 overflow-y-auto px-7 pb-6">
                {{-- The facts, as chips. Each opens a menu; the pick writes
                     straight through. --}}
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <flux:dropdown position="bottom" align="start">
                        <button type="button" class="{{ $chip }}">
                            <span class="size-1.5 rounded-full" style="background-color: {{ $openSquad->color ?? '#a1a1aa' }}"></span>
                            {{ $openSquad->name ?? 'Squad' }}
                            <flux:icon.chevron-down variant="micro" class="text-zinc-400" />
                        </button>
                        <flux:menu>
                            <flux:menu.radio.group wire:model.live="editSquadId">
                                <flux:menu.radio value="">Unassigned</flux:menu.radio>
                                @foreach($squads as $squadOption)
                                <flux:menu.radio value="{{ $squadOption->id }}">
                                    <span class="inline-flex items-center gap-2">
                                        <span class="size-2 rounded-full" style="background-color: {{ $squadOption->color }}"></span>
                                        {{ $squadOption->name }}
                                    </span>
                                </flux:menu.radio>
                                @endforeach
                            </flux:menu.radio.group>
                        </flux:menu>
                    </flux:dropdown>

                    <flux:dropdown position="bottom" align="start">
                        <button type="button" class="{{ $chip }}">
                            {{ $categories->firstWhere('id', (int) $editCategoryId)?->name ?? 'Category' }}
                            <flux:icon.chevron-down variant="micro" class="text-zinc-400" />
                        </button>
                        <flux:menu>
                            <flux:menu.radio.group wire:model.live="editCategoryId">
                                <flux:menu.radio value="">None</flux:menu.radio>
                                @foreach($categories as $category)
                                <flux:menu.radio value="{{ $category->id }}">{{ $category->name }}</flux:menu.radio>
                                @endforeach
                            </flux:menu.radio.group>
                        </flux:menu>
                    </flux:dropdown>

                    @php [$priorityLabel, $priorityGlyph, $priorityColor] = $priorities[$editPriority] ?? $priorities['medium']; @endphp
                    <flux:dropdown position="bottom" align="start">
                        <button type="button" class="{{ $chip }}">
                            <flux:icon :icon="$priorityGlyph" variant="micro" class="{{ $priorityColor }}" />
                            {{ $priorityLabel }}
                            <flux:icon.chevron-down variant="micro" class="text-zinc-400" />
                        </button>
                        <flux:menu>
                            <flux:menu.radio.group wire:model.live="editPriority">
                                @foreach($priorities as $value => [$label, $glyph, $color])
                                <flux:menu.radio value="{{ $value }}">
                                    <span class="inline-flex items-center gap-2"><flux:icon :icon="$glyph" variant="micro" class="{{ $color }}" /> {{ $label }}</span>
                                </flux:menu.radio>
                                @endforeach
                            </flux:menu.radio.group>
                        </flux:menu>
                    </flux:dropdown>

                    <span class="w-px h-5 mx-1 bg-zinc-200 dark:bg-zinc-700"></span>

                    {{-- Where it lives in Atlassian. The chip opens a small
                         box to paste the link; once set, the chip carries the
                         issue key and the box gains a way out to the issue. --}}
                    @foreach([
                        ['field' => 'editJiraEpicUrl', 'url' => $openEpic->jira_epic_url, 'kind' => 'jira', 'label' => 'Jira epic',
                         'placeholder' => 'https://…/browse/KEY-1', 'classes' => 'text-blue-700 dark:text-blue-400 border-blue-200 dark:border-blue-900 bg-blue-50 dark:bg-blue-950/40'],
                        ['field' => 'editJpdIdeaUrl', 'url' => $openEpic->jpd_idea_url, 'kind' => 'idea', 'label' => 'JPD idea',
                         'placeholder' => 'https://…?selectedIssue=KEY-1', 'classes' => 'text-purple-700 dark:text-purple-400 border-purple-200 dark:border-purple-900 bg-purple-50 dark:bg-purple-950/40'],
                    ] as $link)
                    <flux:dropdown position="bottom" align="start" wire:key="link-{{ $link['kind'] }}">
                        @if($link['url'])
                        <button type="button" class="{{ $chip }} {{ $link['classes'] }}">
                            <flux:icon.arrow-top-right-on-square variant="micro" class="size-3" />
                            {{ \App\Support\AtlassianLink::issueKey($link['url']) ?? $link['label'] }}
                        </button>
                        @else
                        <button type="button" class="{{ $ghostChip }}">
                            <flux:icon.plus variant="micro" class="size-3.5" />
                            {{ $link['label'] }}
                        </button>
                        @endif
                        <flux:popover class="w-80 p-3! space-y-2">
                            <div class="flex items-center justify-between">
                                <span class="{{ $micro }}">{{ $link['label'] }}</span>
                                @if($link['url'])
                                <x-atlassian-link :url="$link['url']" :kind="$link['kind']" />
                                @endif
                            </div>
                            <flux:input type="url" size="sm" placeholder="{{ $link['placeholder'] }}" clearable
                                        wire:model.live.debounce.600ms="{{ $link['field'] }}" />
                            <flux:error name="{{ $link['field'] }}" />
                        </flux:popover>
                    </flux:dropdown>
                    @endforeach
                </div>

                {{-- Why it stopped --}}
                @if($openEpic->openPause)
                <div class="mt-5 flex gap-2.5 rounded-lg border border-amber-200 dark:border-amber-900/60 bg-amber-50/60 dark:bg-amber-950/20 px-3.5 py-3">
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

                {{-- Pause work --}}
                @if($panel === 'pause')
                <form wire:submit="pauseWork" class="mt-5 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 space-y-4">
                    <div>
                        <flux:heading size="base">Why is it stopping?</flux:heading>
                        <flux:text class="mt-0.5 text-xs">
                            Clears bookings from this week on. Past weeks stay — they are the record
                            of what was actually spent.
                        </flux:text>
                    </div>

                    <flux:input wire:model="pauseReason" label="Reason" size="sm"
                                placeholder="Deprioritised for the scheduler launch" autofocus />

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

                {{-- People: who is on it. Adding someone books them from this
                     week through the end of the quarter in one click -- the
                     fine-grained weeks live on the epic page, not here. --}}
                <div class="mt-6">
                    <div class="{{ $micro }}">People</div>
                    <div class="mt-2.5 flex flex-wrap items-center gap-2">
                        @if($openCrew->isEmpty())
                        <flux:text class="text-sm">No one on this yet.</flux:text>
                        @endif

                        @foreach($openCrew as $engineer)
                        <span wire:key="crew-{{ $engineer->id }}"
                              class="group/person inline-flex items-center gap-1.5 h-8 pl-1 pr-1 rounded-full border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-[13px] font-medium text-zinc-800 dark:text-zinc-100">
                            <x-engineer-avatar :engineer="$engineer" size="xs" :tooltip="false" />
                            <span class="pr-0.5">{{ $engineer->name }}</span>
                            <button type="button" wire:click="unstaff({{ $engineer->id }})"
                                    title="Take {{ $engineer->name }} off from this week on"
                                    class="inline-flex items-center justify-center size-5 rounded-full text-zinc-400
                                           hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-100 transition-colors">
                                <flux:icon.x-mark variant="micro" class="size-3.5" />
                                <span class="sr-only">Remove {{ $engineer->name }}</span>
                            </button>
                        </span>
                        @endforeach

                        @if($available->isNotEmpty())
                        {{-- The picker filters as you type, on the client:
                             the whole team is already in the page, so no
                             request is made until someone is picked. --}}
                        <flux:dropdown position="bottom" align="start">
                            <button type="button" aria-label="Add a person"
                                    class="inline-flex items-center gap-1 h-8 pl-2.5 pr-3 rounded-full border border-dashed border-accent/60 text-[13px] font-medium text-accent-content
                                           hover:bg-accent/5 transition-colors cursor-pointer">
                                <flux:icon.plus variant="micro" class="size-3.5" />
                                Add
                            </button>
                            <flux:popover class="w-72 p-0! overflow-hidden">
                                <flux:command class="border-0! rounded-none! shadow-none!">
                                    <flux:command.input placeholder="Find a person…" autofocus />
                                    <flux:command.items class="max-h-72 overflow-y-auto p-1">
                                        @foreach($available as $person)
                                        {{-- Picking closes the popover: the assignment lands on the
                                             chip row, so there is nothing left to do in here. --}}
                                        <flux:command.item wire:click="assign({{ $person->id }})" wire:key="pick-{{ $person->id }}" class="gap-2.5"
                                                           x-on:click="$el.closest('[popover]')?.hidePopover()">
                                            <x-engineer-avatar :engineer="$person" size="xs" :tooltip="false" />
                                            <span class="flex-1 min-w-0 truncate">{{ $person->name }}</span>
                                            @if($person->squad)
                                            <span class="text-[10.5px] font-semibold shrink-0" style="color: {{ $person->squad->color }}">{{ $person->squad->name }}</span>
                                            @endif
                                        </flux:command.item>
                                        @endforeach
                                    </flux:command.items>
                                </flux:command>
                            </flux:popover>
                        </flux:dropdown>
                        @endif
                    </div>
                </div>

                {{-- Comments: the part of the record that only reads as prose.
                     One level of threading -- replies sit under their root behind
                     a left rule, and replying to a reply joins the same thread. --}}
                <div class="mt-6 pt-5 border-t border-zinc-100 dark:border-zinc-800">
                    <div class="flex items-center gap-2">
                        <span class="text-[13px] font-semibold text-zinc-900 dark:text-zinc-100">Comments</span>
                        @if($openCommentCount > 0)
                        <span class="text-[10.5px] font-bold px-1.5 py-px rounded-full bg-zinc-100 dark:bg-zinc-700 text-zinc-500 dark:text-zinc-300">{{ $openCommentCount }}</span>
                        @endif
                    </div>

                    <div class="mt-4 space-y-4">
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
                                    {{-- Your own comment's rarer actions live behind one
                                         quiet button; Reply stays out where everyone's is. --}}
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" aria-label="Comment actions" />
                                        <flux:menu>
                                            <flux:menu.item icon="pencil" wire:click="editComment({{ $comment->id }})">Edit</flux:menu.item>
                                            <flux:menu.item icon="trash" variant="danger"
                                                            wire:click="deleteComment({{ $comment->id }})"
                                                            wire:confirm="Delete this comment{{ ($openReplies[$comment->id] ?? collect())->isNotEmpty() ? ' and its replies' : '' }}?">Delete</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                    @endif
                                </div>

                                @if($editingCommentId === $comment->id)
                                <form wire:submit="updateComment" class="mt-1 space-y-2">
                                    <x-mention-box :members="$mentionable"><flux:textarea wire:model="editCommentBody" rows="2" x-on:keydown.enter="if (open || $event.shiftKey || $event.isComposing) return; $event.preventDefault(); $el.closest('form').requestSubmit()" /></x-mention-box>
                                    <flux:error name="editCommentBody" />
                                    <div class="flex justify-end gap-2">
                                        <flux:button type="button" size="xs" variant="ghost" wire:click="cancelEditComment">Cancel</flux:button>
                                        <flux:button type="submit" size="xs" variant="filled">Save</flux:button>
                                    </div>
                                </form>
                                @else
                                <div class="text-sm whitespace-pre-line">{{ Mentions::render($comment, $openEpic->team) }}</div>
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
                                            <flux:dropdown position="bottom" align="end">
                                                <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" aria-label="Reply actions" />
                                                <flux:menu>
                                                    <flux:menu.item icon="pencil" wire:click="editComment({{ $reply->id }})">Edit</flux:menu.item>
                                                    <flux:menu.item icon="trash" variant="danger"
                                                                    wire:click="deleteComment({{ $reply->id }})"
                                                                    wire:confirm="Delete this reply?">Delete</flux:menu.item>
                                                </flux:menu>
                                            </flux:dropdown>
                                            @endif
                                        </div>

                                        @if($editingCommentId === $reply->id)
                                        <form wire:submit="updateComment" class="mt-1 space-y-2">
                                            <x-mention-box :members="$mentionable"><flux:textarea wire:model="editCommentBody" rows="2" x-on:keydown.enter="if (open || $event.shiftKey || $event.isComposing) return; $event.preventDefault(); $el.closest('form').requestSubmit()" /></x-mention-box>
                                            <flux:error name="editCommentBody" />
                                            <div class="flex justify-end gap-2">
                                                <flux:button type="button" size="xs" variant="ghost" wire:click="cancelEditComment">Cancel</flux:button>
                                                <flux:button type="submit" size="xs" variant="filled">Save</flux:button>
                                            </div>
                                        </form>
                                        @else
                                        <div class="text-sm whitespace-pre-line">{{ Mentions::render($reply, $openEpic->team) }}</div>
                                        @endif
                                    </div>
                                </div>
                                @endforeach

                                @if($replyingToId === $comment->id || ($openReplies[$comment->id] ?? collect())->contains('id', $replyingToId))
                                <form wire:submit="addComment" class="mt-2 ml-1 pl-3 border-l border-zinc-200 dark:border-zinc-700 space-y-2">
                                    <x-mention-box :members="$mentionable"><flux:textarea wire:model="commentBody" rows="2" placeholder="Reply…" autofocus x-on:keydown.enter="if (open || $event.shiftKey || $event.isComposing) return; $event.preventDefault(); $el.closest('form').requestSubmit()" /></x-mention-box>
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
                    </div>
                </div>
            </div>

            {{-- The composer, pinned. While a reply box is open up in the
                 thread this steps aside, so there is only ever one place to
                 type. --}}
            <div class="shrink-0 border-t border-zinc-100 dark:border-zinc-800 px-7 py-4 bg-white dark:bg-zinc-800">
                @if($replyingToId === null)
                <form wire:submit="addComment" class="space-y-2.5">
                    <x-mention-box :members="$mentionable"><flux:textarea wire:model="commentBody" rows="2" placeholder="Leave a comment… @ to mention someone" x-on:keydown.enter="if (open || $event.shiftKey || $event.isComposing) return; $event.preventDefault(); $el.closest('form').requestSubmit()" /></x-mention-box>
                    <flux:error name="commentBody" />
                    <div class="flex items-center justify-between gap-2">
                        <flux:button size="sm" variant="ghost" icon="arrow-top-right-on-square" href="/epics/{{ $openEpic->id }}/edit" wire:navigate>Full view</flux:button>
                        <div class="flex items-center gap-3">
                            <span class="text-[11px] text-zinc-400 dark:text-zinc-500 hidden sm:inline">Enter to send · Shift+Enter for a new line</span>
                            <flux:button type="submit" size="sm" variant="primary">Comment</flux:button>
                        </div>
                    </div>
                </form>
                @else
                <div class="flex items-center justify-between">
                    <flux:button size="sm" variant="ghost" icon="arrow-top-right-on-square" href="/epics/{{ $openEpic->id }}/edit" wire:navigate>Full view</flux:button>
                    <flux:text class="text-xs">Replying above</flux:text>
                </div>
                @endif
            </div>
        </div>
        @endif
        </div>

        {{-- What the dialog opens onto while the epic is on its way: the
             shape of the header above, in grey. --}}
        <div x-show="opening" x-cloak class="animate-pulse p-7 space-y-3" aria-hidden="true">
            <div class="h-4 w-20 rounded bg-zinc-200 dark:bg-zinc-700"></div>
            <div class="mt-3 h-7 w-3/4 rounded bg-zinc-200 dark:bg-zinc-700"></div>
            <div class="h-4 w-full rounded bg-zinc-100 dark:bg-zinc-800"></div>
            <div class="h-4 w-5/6 rounded bg-zinc-100 dark:bg-zinc-800"></div>
            <div class="mt-4 flex gap-2">
                <div class="h-7 w-24 rounded-lg bg-zinc-100 dark:bg-zinc-800"></div>
                <div class="h-7 w-20 rounded-lg bg-zinc-100 dark:bg-zinc-800"></div>
                <div class="h-7 w-16 rounded-lg bg-zinc-100 dark:bg-zinc-800"></div>
            </div>
        </div>
    </flux:modal>
    @endisland
</div>
