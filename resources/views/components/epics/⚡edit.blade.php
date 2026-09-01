<?php

use App\Models\Allocation;
use App\Models\Engineer;
use App\Models\Epic;
use App\Models\EpicComment;
use App\Models\EpicQuarterPlan;
use App\Models\Status;
use App\Notifications\EpicCommented;
use App\Services\CapacityService;
use App\Support\Quarter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * One epic, at a glance.
 *
 * Everything writes as you type -- there is no save button. Each field is
 * validated and persisted on its own so a half-typed date never blocks a
 * priority change.
 *
 * The week spine is the centre of the page: it reads allocations for the
 * selected quarter and writes them back, so staffing can be adjusted here
 * instead of only from the planning grid.
 */
new #[Layout('components.layouts.app.sidebar')] class extends Component
{
    /** Fields with a validation rule attached; is_recurring has none. */
    private const VALIDATED = ['title', 'description', 'status_id', 'category_id', 'priority', 'start_date', 'end_date', 'jira_epic_url', 'jpd_idea_url'];

    public Epic $epic;

    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('nullable|string')]
    public string $description = '';

    #[Validate('required|exists:statuses,id')]
    public string $status_id = '';

    #[Validate('nullable|exists:categories,id')]
    public string $category_id = '';

    #[Validate('required|in:low,medium,high,critical')]
    public string $priority = 'medium';

    public bool $is_recurring = false;

    #[Validate('nullable|date')]
    public string $start_date = '';

    #[Validate('nullable|date|after_or_equal:start_date')]
    public string $end_date = '';

    #[Validate('nullable|url:https|max:2048', message: 'Paste the full https:// link from Jira.')]
    public string $jira_epic_url = '';

    #[Validate('nullable|url:https|max:2048', message: 'Paste the full https:// link from Product Discovery.')]
    public string $jpd_idea_url = '';

    /** Scopes the spine and the squad plan. Everything else is quarter-agnostic. */
    #[Url]
    public string $quarter = '';

    public array $squad_ids = [];

    /** squadId => planned points */
    public array $planned_points = [];

    /** squadId => delivered points (manually maintained actuals) */
    public array $delivered_points = [];

    public bool $confirmingDeletion = false;

    public string $commentBody = '';

    /** Comment a reply composer is open under, or null for the top-level box. */
    public ?int $replyingToId = null;

    /** Comment whose body is being edited inline, or null. */
    public ?int $editingCommentId = null;

    public string $editCommentBody = '';

    public function mount(Epic $epic): void
    {
        $this->authorize('update', $epic);

        $this->epic = $epic;
        $this->title = $epic->title;
        $this->description = $epic->description ?? '';
        $this->status_id = (string) ($epic->status_id ?? Status::defaultFor($epic->team)?->id ?? '');
        $this->category_id = (string) ($epic->category_id ?? '');
        // A freshly created instance may not have the column defaults loaded
        // back yet, so both are coerced rather than assumed.
        $this->priority = $epic->priority ?? 'medium';
        $this->is_recurring = (bool) $epic->is_recurring;
        $this->start_date = $epic->start_date?->format('Y-m-d') ?? '';
        $this->end_date = $epic->end_date?->format('Y-m-d') ?? '';
        $this->jira_epic_url = $epic->jira_epic_url ?? '';
        $this->jpd_idea_url = $epic->jpd_idea_url ?? '';

        $this->quarter = $this->quarter ?: $this->openingQuarter()->key();

        $this->loadQuarter();
    }

    /** Land on the current quarter when the epic runs there, else its first plan. */
    private function openingQuarter(): Quarter
    {
        $current = Quarter::current();

        if ($this->epic->quarterPlans()->forQuarter($current)->exists()) {
            return $current;
        }

        $first = $this->epic->quarterPlans()->orderBy('year')->orderBy('quarter')->first();

        return $first ? $first->toQuarter() : $current;
    }

    private function loadQuarter(): void
    {
        $plans = $this->epic->quarterPlans()->forQuarter(Quarter::parse($this->quarter))->get();

        $this->squad_ids = $plans->pluck('squad_id')->all();
        $this->planned_points = $plans->pluck('planned_points', 'squad_id')->all();
        $this->delivered_points = $plans->pluck('delivered_points', 'squad_id')->all();
    }

    // ------------------------------------------------------------- autosaving

    public function updated(string $property): void
    {
        if ($property === 'quarter') {
            $this->loadQuarter();

            return;
        }

        if ($property === 'squad_ids') {
            $this->syncSquads();

            return;
        }

        if (str_contains($property, '.')) {
            [$base, $squadId] = explode('.', $property, 2);

            if (in_array($base, ['planned_points', 'delivered_points'], true)) {
                $this->savePoints((int) $squadId);
            }

            return;
        }

        $this->saveField($property);
    }

    /**
     * Persists one column. Validation is scoped to the field that changed so
     * an incomplete value elsewhere on the page cannot block the write.
     */
    private function saveField(string $property): void
    {
        $columns = [
            'title' => fn () => $this->title,
            'description' => fn () => $this->description,
            'status_id' => fn () => $this->status_id ?: null,
            'category_id' => fn () => $this->category_id ?: null,
            'priority' => fn () => $this->priority,
            'start_date' => fn () => $this->start_date ?: null,
            'end_date' => fn () => $this->end_date ?: null,
            'is_recurring' => fn () => $this->is_recurring,
            'jira_epic_url' => fn () => trim($this->jira_epic_url) ?: null,
            'jpd_idea_url' => fn () => trim($this->jpd_idea_url) ?: null,
        ];

        if (! isset($columns[$property])) {
            return;
        }

        $this->authorize('update', $this->epic);

        // The two dates constrain each other, so either edit re-checks both.
        if (in_array($property, ['start_date', 'end_date'], true)) {
            $this->validateOnly('start_date');
            $this->validateOnly('end_date');
        } elseif (in_array($property, self::VALIDATED, true)) {
            $this->validateOnly($property);
        }

        $statusChanged = $property === 'status_id'
            && (string) $this->epic->status_id !== $this->status_id;

        $this->epic->update([$property => $columns[$property]()]);

        // Whatever the old pause was about, it ended when the epic moved --
        // the same rule the board applies when a card is dragged.
        if ($statusChanged) {
            $this->epic->pauses()->open()->update(['resumed_at' => now()]);
        }

        $this->saved();
    }

    private function syncSquads(): void
    {
        $this->authorize('update', $this->epic);

        $quarter = Quarter::parse($this->quarter);

        $chosen = Auth::user()->currentTeam->squads()
            ->whereIn('id', array_map('intval', $this->squad_ids))
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($quarter, $chosen) {
            // Only this quarter's plans are touched; other quarters the epic
            // runs in are left alone.
            $this->epic->quarterPlans()
                ->forQuarter($quarter)
                ->whereNotIn('squad_id', $chosen ?: [0])
                ->delete();

            foreach ($chosen as $squadId) {
                $this->planned_points[$squadId] ??= 25;

                EpicQuarterPlan::firstOrCreate(
                    [
                        'epic_id' => $this->epic->id,
                        'squad_id' => $squadId,
                        'year' => $quarter->year,
                        'quarter' => $quarter->quarter,
                    ],
                    ['planned_points' => $this->normalise($this->planned_points[$squadId])],
                );
            }
        });

        $this->squad_ids = $chosen;
        $this->saved();
    }

    private function savePoints(int $squadId): void
    {
        $this->authorize('update', $this->epic);

        $plan = $this->epic->quarterPlans()
            ->forQuarter(Quarter::parse($this->quarter))
            ->where('squad_id', $squadId)
            ->first();

        if (! $plan) {
            return;
        }

        $plan->update([
            'planned_points' => $this->normalise($this->planned_points[$squadId] ?? null),
            'delivered_points' => $this->normalise($this->delivered_points[$squadId] ?? null),
        ]);

        $this->saved();
    }

    private function normalise(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : max(0, (int) $value);
    }

    private function saved(): void
    {
        $this->dispatch('epic-saved');
    }

    // -------------------------------------------------------------- staffing

    /**
     * Books someone across the weeks this epic actually runs, clamped to the
     * selected quarter. Trim from there in the spine.
     */
    public function addEngineer(int $engineerId): void
    {
        $this->authorize('update', $this->epic);
        $this->assertEngineerInTeam($engineerId);

        $weeks = $this->openingWeeks();

        DB::transaction(function () use ($engineerId, $weeks) {
            foreach ($weeks as $week) {
                Allocation::firstOrCreate(
                    ['engineer_id' => $engineerId, 'epic_id' => $this->epic->id, 'week_start' => $week],
                    ['share' => 1.0],
                );
            }
        });

        $this->saved();
    }

    public function toggleWeek(int $engineerId, string $week): void
    {
        $this->authorize('update', $this->epic);
        $this->assertEngineerInTeam($engineerId);
        $this->assertWeekInQuarter($week);

        $existing = Allocation::where('engineer_id', $engineerId)
            ->where('epic_id', $this->epic->id)
            ->inWeek($week)
            ->first();

        $existing
            ? $existing->delete()
            : Allocation::create([
                'engineer_id' => $engineerId,
                'epic_id' => $this->epic->id,
                'week_start' => $week,
                'share' => 1.0,
            ]);

        $this->saved();
    }

    /** Dragging across a run of weeks must not fire a request per cell. */
    public function paintWeeks(int $engineerId, string $fromWeek, string $toWeek, bool $erase = false): void
    {
        $this->authorize('update', $this->epic);
        $this->assertEngineerInTeam($engineerId);

        $weeks = collect($this->quarterWeeks())->map(fn ($w) => $w->toDateString());

        $from = $weeks->search($fromWeek);
        $to = $weeks->search($toWeek);

        if ($from === false || $to === false) {
            return;
        }

        $slice = $weeks->slice(min($from, $to), abs($to - $from) + 1);

        DB::transaction(function () use ($slice, $engineerId, $erase) {
            if ($erase) {
                Allocation::where('engineer_id', $engineerId)
                    ->where('epic_id', $this->epic->id)
                    ->whereIn('week_start', $slice)
                    ->delete();

                return;
            }

            foreach ($slice as $week) {
                Allocation::firstOrCreate(
                    ['engineer_id' => $engineerId, 'epic_id' => $this->epic->id, 'week_start' => $week],
                    ['share' => 1.0],
                );
            }
        });

        $this->saved();
    }

    /** Clears someone off this epic for the selected quarter only. */
    public function removeEngineer(int $engineerId): void
    {
        $this->authorize('update', $this->epic);
        $this->assertEngineerInTeam($engineerId);

        $weeks = collect($this->quarterWeeks())->map(fn ($w) => $w->toDateString());

        Allocation::where('engineer_id', $engineerId)
            ->where('epic_id', $this->epic->id)
            ->whereIn('week_start', $weeks)
            ->delete();

        $this->saved();
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->epic);
        $this->epic->delete();

        $this->redirect('/epics', navigate: true);
    }

    /** @return array<int, CarbonImmutable> */
    private function quarterWeeks(): array
    {
        return Auth::user()->currentTeam->weekCalendar()->weeksIn(Quarter::parse($this->quarter));
    }

    /** @return array<int, string> */
    private function openingWeeks(): array
    {
        $weeks = collect($this->quarterWeeks());

        $start = $this->start_date ? CarbonImmutable::parse($this->start_date) : null;
        $end = $this->end_date ? CarbonImmutable::parse($this->end_date) : null;

        $overlapping = $weeks->filter(fn (CarbonImmutable $week) => (! $start || $week->addDays(6)->gte($start))
            && (! $end || $week->lte($end)));

        // No dates, or dates that miss this quarter entirely: book the quarter.
        return ($overlapping->isEmpty() ? $weeks : $overlapping)
            ->map(fn (CarbonImmutable $week) => $week->toDateString())
            ->all();
    }

    private function assertEngineerInTeam(int $engineerId): void
    {
        abort_unless(
            Auth::user()->currentTeam->engineers()->whereKey($engineerId)->exists(),
            403,
        );
    }

    private function assertWeekInQuarter(string $week): void
    {
        abort_unless(
            collect($this->quarterWeeks())->contains(fn ($w) => $w->toDateString() === $week),
            422,
        );
    }

    // --------------------------------------------------------------- comments

    public function addComment(): void
    {
        $this->authorize('update', $this->epic);

        $this->validate([
            'commentBody' => 'required|string|max:5000',
        ], [
            'commentBody.required' => 'Say something first.',
        ]);

        $parentId = null;

        if ($this->replyingToId !== null) {
            $parent = $this->epic->comments()->findOr($this->replyingToId, fn () => abort(403));

            // Replying to a reply joins the same thread: threading is one
            // level deep, so everything re-roots onto the top-level comment.
            $parentId = $parent->parent_id ?? $parent->id;
        }

        $comment = EpicComment::create([
            'epic_id' => $this->epic->id,
            'user_id' => Auth::id(),
            'parent_id' => $parentId,
            'body' => $this->commentBody,
        ]);

        // Everyone already in the conversation hears about it, except the
        // person who just spoke.
        Notification::send(
            $this->epic->participants()->reject(fn ($user) => $user->id === Auth::id()),
            new EpicCommented($comment),
        );

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
        $comment = $this->epic->comments()->findOr($commentId, fn () => abort(403));

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
        $comment = $this->epic->comments()->findOr($this->editingCommentId, fn () => abort(403));

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
        $comment = $this->epic->comments()->findOr($commentId, fn () => abort(403));

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

    public function with(): array
    {
        $team = Auth::user()->currentTeam;
        $capacity = CapacityService::for($team);
        $quarter = Quarter::parse($this->quarter);
        $weeks = $capacity->calendar()->weeksIn($quarter);
        $currentWeek = $capacity->currentWeek()->toDateString();

        $allocations = Allocation::where('epic_id', $this->epic->id)
            ->betweenWeeks($weeks[0] ?? $quarter->start(), end($weeks) ?: $quarter->end())
            ->get()
            ->groupBy(fn ($a) => $a->engineer_id);

        $booked = Engineer::whereIn('id', $allocations->keys())
            ->with('squad')
            ->ordered()
            ->get();

        $rows = $booked->map(function (Engineer $engineer) use ($allocations, $capacity, $weeks, $currentWeek) {
            $mine = $allocations[$engineer->id]->keyBy(fn ($a) => $a->week_start->toDateString());

            return [
                'engineer' => $engineer,
                'weeks' => $mine->count(),
                'points' => (int) $mine->sum(fn ($a) => $capacity->weeklyCapacity($engineer->id, $a->week_start)),
                'cells' => collect($weeks)->map(fn (CarbonImmutable $week) => [
                    'week' => $week->toDateString(),
                    'label' => $week->format('M j'),
                    'booked' => $mine->has($week->toDateString()),
                    'capacity' => $capacity->weeklyCapacity($engineer->id, $week),
                    'over' => $capacity->isOverAllocated($engineer->id, $week),
                    'isCurrent' => $week->toDateString() === $currentWeek,
                ]),
            ];
        });

        // Staffed points broken out by squad -- the segments of the rail meter.
        $bySquad = $rows->groupBy(fn ($row) => $row['engineer']->squad?->name ?? 'No squad')
            ->map(fn ($group) => [
                'color' => $group->first()['engineer']->squad?->color ?? '#71717a',
                'points' => $group->sum('points'),
            ])
            ->sortByDesc('points')
            ->values();

        $seen = [];
        $columns = collect($weeks)->map(function (CarbonImmutable $week) use (&$seen, $currentWeek) {
            $month = $week->format('M');
            $first = ! isset($seen[$month]);
            $seen[$month] = true;

            return [
                'key' => $week->toDateString(),
                'month' => $first ? mb_strtoupper($month) : '',
                'isCurrent' => $week->toDateString() === $currentWeek,
            ];
        });

        $comments = $this->epic->comments()->with('user')->oldest('created_at')->get();

        return [
            'squads' => $team->squads()->ordered()->get(),
            'categories' => $team->categories()->ordered()->get(),
            'statuses' => $team->statuses()->ordered()->get(),
            'quarters' => Quarter::current()->previous()->through(9),
            'quarterLabel' => $quarter->label(),
            'rows' => $rows,
            'columns' => $columns,
            'bySquad' => $bySquad,
            'available' => $team->engineers()->with('squad')->active()->ordered()->get()
                ->reject(fn (Engineer $e) => $allocations->has($e->id))
                ->values(),
            'staffedPoints' => (int) $rows->sum('points'),
            'plannedPoints' => (int) collect($this->planned_points)
                ->only($this->squad_ids)
                ->sum(),
            'openPause' => $this->epic->openPause(),
            'openComments' => $comments->whereNull('parent_id')->values(),
            'openReplies' => $comments->whereNotNull('parent_id')->groupBy('parent_id'),
        ];
    }
};
?>

@php
    // One structural device runs through the page: a small, wide-tracked label
    // above every block of data. Colour only ever comes from squad or category.
    $micro = 'text-[10px] font-semibold uppercase tracking-[0.14em] text-zinc-400 dark:text-zinc-500';
    $panel = 'rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900';
    $cols = 'grid-template-columns: repeat('.max($columns->count(), 1).', minmax(0, 1fr));';
@endphp

<div class="max-w-6xl"
     x-data="{ saved: false, timer: null }"
     x-on:epic-saved.window="saved = true; clearTimeout(timer); timer = setTimeout(() => saved = false, 1600)">

    {{-- Page bar: where you came from, whether it stuck, what quarter you're in --}}
    <div class="flex items-center justify-between gap-4 pb-5">
        <flux:button href="/epics" variant="subtle" size="sm" icon="arrow-left" wire:navigate>Epics</flux:button>

        <div class="flex items-center gap-3">
            <span class="text-[11px] font-medium text-zinc-400 tabular-nums" wire:loading>Saving…</span>
            <span x-cloak x-show="saved" x-transition.opacity.duration.150ms wire:loading.remove
                  class="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-600 dark:text-emerald-400">
                <flux:icon.check variant="micro" class="size-3.5" />Saved
            </span>

            <flux:select wire:model.live="quarter" size="sm" class="w-32">
                @foreach($quarters as $option)
                <flux:select.option value="{{ $option->key() }}">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_20rem] gap-6 lg:gap-8 items-start">

        {{-- ─────────────────────────────────────────────── main: title, people, plan --}}
        <div class="min-w-0 space-y-6">

            <div>
                @if($epic->status)
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold"
                      style="background-color: {{ $epic->status->color }}1f; color: {{ $epic->status->color }}">
                    <span class="size-1.5 rounded-full" style="background-color: {{ $epic->status->color }}"></span>
                    {{ $epic->status->name }}
                </span>
                @endif

                <input type="text" wire:model.live.debounce.600ms="title" placeholder="Untitled epic"
                       aria-label="Epic title"
                       class="mt-3 w-full bg-transparent border-0 border-b border-transparent px-0 py-1
                              text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100
                              placeholder:text-zinc-300 dark:placeholder:text-zinc-600
                              hover:border-zinc-200 dark:hover:border-zinc-700
                              focus:border-accent focus:ring-0 focus:outline-none transition-colors" />
                <flux:error name="title" />

                <textarea wire:model.live.debounce.600ms="description" rows="1" placeholder="Add a description"
                          aria-label="Description"
                          x-data x-init="$el.style.height = $el.scrollHeight + 'px'"
                          x-on:input="$el.style.height = 'auto'; $el.style.height = $el.scrollHeight + 'px'"
                          class="mt-2 w-full resize-none overflow-hidden bg-transparent border-0 px-0 py-1
                                 text-[15px] leading-relaxed text-zinc-600 dark:text-zinc-400
                                 placeholder:text-zinc-300 dark:placeholder:text-zinc-600
                                 focus:ring-0 focus:outline-none"></textarea>
            </div>

            @if($openPause)
            <flux:callout icon="pause-circle" color="amber">
                <flux:callout.heading>Paused since {{ $openPause->paused_at->format('M j, Y') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ $openPause->reason ?? 'No reason recorded.' }}
                    @if($openPause->supersededBy)
                        Capacity went to <strong>{{ $openPause->supersededBy->title }}</strong>.
                    @endif
                </flux:callout.text>
            </flux:callout>
            @endif

            {{-- The spine. Who is on this epic, and which weeks they hold. --}}
            <div class="{{ $panel }} p-5">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
                    <div>
                        <div class="{{ $micro }}">Who's on it</div>
                        <div class="mt-1 text-[13px] text-zinc-500 dark:text-zinc-400 tabular-nums">
                            {{ $quarterLabel }} · {{ $columns->count() }} weeks · click or drag a week to book it
                        </div>
                    </div>

                    @if($available->isNotEmpty())
                    <flux:dropdown position="bottom" align="end">
                        <flux:button size="sm" icon="plus" variant="filled">Add person</flux:button>

                        <flux:menu class="max-h-80 overflow-y-auto">
                            @foreach($available->groupBy(fn ($e) => $e->squad?->name ?? 'No squad') as $squadName => $people)
                            <flux:menu.group heading="{{ $squadName }}">
                                @foreach($people as $person)
                                <flux:menu.item wire:click="addEngineer({{ $person->id }})">
                                    {{ $person->name }}
                                </flux:menu.item>
                                @endforeach
                            </flux:menu.group>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                    @endif
                </div>

                {{-- contain:paint as well as overflow: without it the spine's
                     min-width still widens the document on narrow screens. --}}
                <div class="overflow-x-auto [contain:paint] -mx-1 px-1">
                    <div class="min-w-[34rem]"
                        x-data="{
                            dragging: false, engineer: null, from: null, to: null, erase: false,
                            start(engineerId, week, booked) {
                                this.dragging = true; this.engineer = engineerId;
                                this.from = week; this.to = week; this.erase = booked;
                            },
                            extend(engineerId, week) {
                                if (! this.dragging || engineerId !== this.engineer) return;
                                this.to = week;
                            },
                            finish() {
                                if (! this.dragging) return;
                                this.from === this.to
                                    ? $wire.toggleWeek(this.engineer, this.from)
                                    : $wire.paintWeeks(this.engineer, this.from, this.to, this.erase);
                                this.dragging = false; this.engineer = null;
                            },
                            inDrag(engineerId, week) {
                                if (! this.dragging || engineerId !== this.engineer) return false;
                                return (week >= this.from && week <= this.to) || (week >= this.to && week <= this.from);
                            }
                        }"
                        x-on:mouseup.window="finish()">

                        {{-- Month ruler. NOW replaces the label in the live week. --}}
                        <div class="flex items-end gap-3 pb-2">
                            <div class="w-36 shrink-0"></div>
                            <div class="flex-1 min-w-0 grid gap-[3px]" style="{{ $cols }}">
                                @foreach($columns as $column)
                                <div class="text-[9px] font-semibold tracking-[0.1em] leading-none text-center whitespace-nowrap
                                            {{ $column['isCurrent'] ? 'text-accent-content' : 'text-zinc-300 dark:text-zinc-600' }}">
                                    {{ $column['isCurrent'] ? 'NOW' : $column['month'] }}
                                </div>
                                @endforeach
                            </div>
                            <div class="w-16 shrink-0"></div>
                            <div class="w-5 shrink-0"></div>
                        </div>

                        @forelse($rows as $row)
                        @php $color = $row['engineer']->squad->color ?? '#71717a'; @endphp
                        <div class="group flex items-center gap-3 py-1" wire:key="staff-{{ $row['engineer']->id }}">
                            <div class="w-36 shrink-0 flex items-center gap-2 min-w-0">
                                <x-engineer-avatar :engineer="$row['engineer']" size="xs" :tooltip="false" />
                                <span class="min-w-0">
                                    <a href="/engineers/{{ $row['engineer']->id }}/edit" wire:navigate
                                       class="block text-[13px] font-medium truncate text-zinc-800 dark:text-zinc-200 hover:underline">
                                        {{ $row['engineer']->name }}
                                    </a>
                                    <span class="block text-[10px] truncate text-zinc-400 dark:text-zinc-500">
                                        {{ $row['engineer']->squad->name ?? 'No squad' }}
                                    </span>
                                </span>
                            </div>

                            <div class="flex-1 min-w-0 grid gap-[3px]" style="{{ $cols }}">
                                @foreach($row['cells'] as $cell)
                                <button type="button"
                                        x-on:mousedown.prevent="start({{ $row['engineer']->id }}, '{{ $cell['week'] }}', {{ $cell['booked'] ? 'true' : 'false' }})"
                                        x-on:mouseenter="extend({{ $row['engineer']->id }}, '{{ $cell['week'] }}')"
                                        :class="inDrag({{ $row['engineer']->id }}, '{{ $cell['week'] }}') && 'ring-2 ring-zinc-900 dark:ring-white'"
                                        @class([
                                            'h-8 rounded-[3px] select-none transition-colors cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-accent',
                                            'bg-zinc-100 hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700' => ! $cell['booked'] && ! $cell['over'],
                                            'bg-red-100 hover:bg-red-200 dark:bg-red-950/60 dark:hover:bg-red-900/50' => ! $cell['booked'] && $cell['over'],
                                            'opacity-40' => ! $cell['booked'] && $cell['capacity'] === 0,
                                            'ring-2 ring-inset ring-accent' => $cell['isCurrent'],
                                        ])
                                        {{-- Contested weeks are hatched rather than tinted: a squad
                                             colour can itself be red, so a red marker would vanish. --}}
                                        style="{{ $cell['booked'] ? 'background-color: '.$color.';' : '' }}{{ $cell['booked'] && $cell['over'] ? 'background-image: repeating-linear-gradient(45deg, transparent 0 4px, rgba(0,0,0,.34) 4px 8px);' : '' }}"
                                        title="{{ $cell['label'] }} — {{ $cell['booked'] ? 'booked' : 'free' }}, {{ $cell['capacity'] }} pts capacity{{ $cell['over'] ? ($cell['booked'] ? ' — another epic wants this week too' : ' — already booked solid elsewhere') : '' }}">
                                    <span class="sr-only">{{ $cell['booked'] ? 'Unbook' : 'Book' }} {{ $row['engineer']->name }} for week of {{ $cell['label'] }}</span>
                                </button>
                                @endforeach
                            </div>

                            <div class="w-16 shrink-0 text-right text-[11px] tabular-nums text-zinc-500 dark:text-zinc-400">
                                {{ $row['weeks'] }}w · {{ $row['points'] }}p
                            </div>

                            <button type="button" wire:click="removeEngineer({{ $row['engineer']->id }})"
                                    class="w-5 shrink-0 text-zinc-300 dark:text-zinc-600 opacity-0 group-hover:opacity-100 focus:opacity-100
                                           hover:text-red-500 dark:hover:text-red-400 transition"
                                    title="Take {{ $row['engineer']->name }} off this epic in {{ $quarterLabel }}">
                                <flux:icon.x-mark variant="micro" class="size-4" />
                                <span class="sr-only">Remove {{ $row['engineer']->name }}</span>
                            </button>
                        </div>
                        @empty
                        <div class="py-6 text-center">
                            <flux:text class="text-sm">Nobody is booked in {{ $quarterLabel }}.</flux:text>
                            <flux:text class="text-xs mt-1">
                                Add someone above, or spread the whole team at once in the
                                <flux:link href="/planning?quarter={{ $quarter }}" wire:navigate>planning grid</flux:link>.
                            </flux:text>
                        </div>
                        @endforelse

                        @if($rows->count() > 1)
                        <div class="flex items-center gap-3 pt-3 mt-2 border-t border-zinc-100 dark:border-zinc-800">
                            <div class="w-36 shrink-0 {{ $micro }}">Total</div>
                            <div class="flex-1 min-w-0"></div>
                            <div class="w-16 shrink-0 text-right text-[11px] font-semibold tabular-nums text-zinc-700 dark:text-zinc-300">
                                {{ $staffedPoints }}p
                            </div>
                            <div class="w-5 shrink-0"></div>
                        </div>
                        @endif
                    </div>
                </div>

                @php
                    $contested = $rows->contains(fn ($row) => $row['cells']->contains(fn ($cell) => $cell['booked'] && $cell['over']));
                    $solid = $rows->contains(fn ($row) => $row['cells']->contains(fn ($cell) => ! $cell['booked'] && $cell['over']));
                @endphp
                @if($contested || $solid)
                <flux:text class="text-xs mt-4">
                    {{ $contested ? 'Hatched weeks are ones another epic wants from the same person.' : '' }}
                    {{ $solid ? 'Red weeks are ones where that person is already booked solid elsewhere.' : '' }}
                </flux:text>
                @endif
            </div>

            {{-- Squad plan: the estimate, against the actual --}}
            <div class="{{ $panel }} p-5">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                    <div>
                        <div class="{{ $micro }}">Squad plan</div>
                        <div class="mt-1 text-[13px] text-zinc-500 dark:text-zinc-400">
                            What each squad committed to in {{ $quarterLabel }}
                        </div>
                    </div>

                    {{-- Flux puts the width class on the field itself, so the
                         wrapper needs its own width to stop it stretching. --}}
                    <div class="w-full sm:w-44 shrink-0">
                        <flux:select multiple variant="listbox" size="sm" wire:model.live="squad_ids"
                                     placeholder="Add squads" class="w-full">
                            @foreach($squads as $squad)
                            <flux:select.option value="{{ $squad->id }}">{{ $squad->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                </div>

                @if(empty($squad_ids))
                <flux:text class="text-sm">No squad has taken this on for {{ $quarterLabel }} yet.</flux:text>
                @else
                <div class="space-y-2">
                    <div class="flex items-center gap-3">
                        <div class="flex-1 min-w-0"></div>
                        <div class="w-24 shrink-0 text-center {{ $micro }}">Planned</div>
                        <div class="w-24 shrink-0 text-center {{ $micro }}">Delivered</div>
                    </div>

                    @foreach($squads->whereIn('id', $squad_ids) as $squad)
                    <div class="flex items-center gap-3" wire:key="plan-{{ $squad->id }}">
                        <div class="flex-1 min-w-0 flex items-center gap-2">
                            <span class="size-2.5 rounded-full shrink-0" style="background-color: {{ $squad->color }}"></span>
                            <span class="text-[13px] font-medium truncate text-zinc-800 dark:text-zinc-200">{{ $squad->name }}</span>
                        </div>
                        <div class="w-24 shrink-0">
                            <flux:input type="number" min="0" size="sm" class="w-full text-center"
                                        wire:model.live.debounce.700ms="planned_points.{{ $squad->id }}" placeholder="—" />
                        </div>
                        <div class="w-24 shrink-0">
                            <flux:input type="number" min="0" size="sm" class="w-full text-center"
                                        wire:model.live.debounce.700ms="delivered_points.{{ $squad->id }}" placeholder="—" />
                        </div>
                    </div>
                    @endforeach

                    <flux:text class="text-xs pt-1">Delivered is kept by hand — update it at review.</flux:text>
                </div>
                @endif
            </div>

            {{-- Comments: the part of the record that only reads as prose.
                 One level of threading -- replying to a reply joins the same
                 thread. Editing and deleting stay with the author. --}}
            <div class="{{ $panel }} p-5 space-y-4">
                <div class="{{ $micro }}">Comments</div>

                @forelse($openComments as $comment)
                <div wire:key="comment-{{ $comment->id }}" class="flex gap-3">
                    <flux:avatar circle size="sm" :name="$comment->user->name" :src="$comment->user->profile_photo_url" />
                    <div class="flex-1 min-w-0">
                        <div class="flex items-baseline gap-2">
                            <span class="text-sm font-medium truncate">{{ $comment->user->name }}</span>
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
                        <form wire:submit="updateComment" class="mt-1.5 space-y-2">
                            <flux:textarea wire:model="editCommentBody" rows="3" />
                            <flux:error name="editCommentBody" />
                            <div class="flex justify-end gap-2">
                                <flux:button type="button" size="xs" variant="ghost" wire:click="cancelEditComment">Cancel</flux:button>
                                <flux:button type="submit" size="xs" variant="filled">Save</flux:button>
                            </div>
                        </form>
                        @else
                        <div class="text-sm leading-relaxed whitespace-pre-line text-zinc-700 dark:text-zinc-300">{{ $comment->body }}</div>
                        @endif

                        @foreach($openReplies[$comment->id] ?? [] as $reply)
                        <div wire:key="comment-{{ $reply->id }}" class="flex gap-3 mt-3 pl-10">
                            <flux:avatar circle size="sm" :name="$reply->user->name" :src="$reply->user->profile_photo_url" />
                            <div class="flex-1 min-w-0">
                                <div class="flex items-baseline gap-2">
                                    <span class="text-sm font-medium truncate">{{ $reply->user->name }}</span>
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
                                <form wire:submit="updateComment" class="mt-1.5 space-y-2">
                                    <flux:textarea wire:model="editCommentBody" rows="3" />
                                    <flux:error name="editCommentBody" />
                                    <div class="flex justify-end gap-2">
                                        <flux:button type="button" size="xs" variant="ghost" wire:click="cancelEditComment">Cancel</flux:button>
                                        <flux:button type="submit" size="xs" variant="filled">Save</flux:button>
                                    </div>
                                </form>
                                @else
                                <div class="text-sm leading-relaxed whitespace-pre-line text-zinc-700 dark:text-zinc-300">{{ $reply->body }}</div>
                                @endif
                            </div>
                        </div>
                        @endforeach

                        @if($replyingToId === $comment->id || ($openReplies[$comment->id] ?? collect())->contains('id', $replyingToId))
                        <form wire:submit="addComment" class="mt-3 pl-10 space-y-2">
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
                @empty
                <flux:text class="text-sm">Nothing said yet. Leave the first comment.</flux:text>
                @endforelse

                @if($replyingToId === null)
                <form wire:submit="addComment" class="space-y-2">
                    <flux:textarea wire:model="commentBody" rows="3" placeholder="Leave a comment…" />
                    <flux:error name="commentBody" />
                    <div class="flex justify-end">
                        <flux:button type="submit" size="sm" variant="filled">Comment</flux:button>
                    </div>
                </form>
                @endif
            </div>
        </div>

        {{-- ──────────────────────────────────────────────────── rail: the epic's facts --}}
        <aside class="lg:sticky lg:top-6 space-y-5">

            <div class="{{ $panel }} p-5">
                <div class="{{ $micro }}">{{ $quarterLabel }} staffing</div>

                <div class="mt-3 flex items-baseline gap-1.5">
                    <span class="text-4xl font-bold tracking-tight tabular-nums text-zinc-900 dark:text-zinc-100">{{ $staffedPoints }}</span>
                    <span class="text-sm text-zinc-400">pts booked</span>
                </div>

                @php $track = max($staffedPoints, $plannedPoints, 1); @endphp
                <div class="mt-3 flex h-1.5 w-full gap-[2px] rounded-full overflow-hidden bg-zinc-100 dark:bg-zinc-800">
                    @foreach($bySquad as $segment)
                    <div class="h-full first:rounded-l-full last:rounded-r-full"
                         style="width: {{ round($segment['points'] / $track * 100, 2) }}%; background-color: {{ $segment['color'] }}"></div>
                    @endforeach
                </div>

                <div class="mt-2.5 text-[11px] tabular-nums text-zinc-500 dark:text-zinc-400">
                    @if($plannedPoints > 0)
                        against {{ $plannedPoints }} pts planned
                        @if($staffedPoints > $plannedPoints)
                            <span class="text-amber-600 dark:text-amber-400">· {{ $staffedPoints - $plannedPoints }} over</span>
                        @elseif($staffedPoints < $plannedPoints)
                            <span>· {{ $plannedPoints - $staffedPoints }} short</span>
                        @endif
                    @else
                        No estimate set
                    @endif
                </div>
            </div>

            <div class="{{ $panel }} divide-y divide-zinc-100 dark:divide-zinc-800">
                <div class="px-5 py-3.5">
                    <div class="{{ $micro }}">Details</div>
                </div>

                <div class="px-5 py-3 flex items-center gap-3">
                    <label for="epic-status" class="w-20 shrink-0 text-[13px] text-zinc-500 dark:text-zinc-400">Status</label>
                    <flux:select id="epic-status" wire:model.live="status_id" size="sm" class="flex-1">
                        @foreach($statuses as $option)
                        <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="px-5 py-3 flex items-center gap-3">
                    <label for="epic-priority" class="w-20 shrink-0 text-[13px] text-zinc-500 dark:text-zinc-400">Priority</label>
                    <flux:select id="epic-priority" wire:model.live="priority" size="sm" class="flex-1">
                        <flux:select.option value="low">Low</flux:select.option>
                        <flux:select.option value="medium">Medium</flux:select.option>
                        <flux:select.option value="high">High</flux:select.option>
                        <flux:select.option value="critical">Critical</flux:select.option>
                    </flux:select>
                </div>

                <div class="px-5 py-3 flex items-center gap-3">
                    <label for="epic-category" class="w-20 shrink-0 text-[13px] text-zinc-500 dark:text-zinc-400">Category</label>
                    <flux:select id="epic-category" wire:model.live="category_id" size="sm" class="flex-1" placeholder="None">
                        @foreach($categories as $category)
                        <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="px-5 py-3 flex items-center gap-3">
                    <label for="epic-start" class="w-20 shrink-0 text-[13px] text-zinc-500 dark:text-zinc-400">Starts</label>
                    <flux:input id="epic-start" type="date" size="sm" class="flex-1" wire:model.live="start_date" />
                </div>

                <div class="px-5 py-3 flex items-center gap-3">
                    <label for="epic-end" class="w-20 shrink-0 text-[13px] text-zinc-500 dark:text-zinc-400">Ends</label>
                    <flux:input id="epic-end" type="date" size="sm" class="flex-1" wire:model.live="end_date" />
                </div>

                @error('end_date')
                <div class="px-5 py-2.5"><flux:error name="end_date" /></div>
                @enderror

                {{-- Pasted pointers into Atlassian, nothing synced. The chip
                     next to a saved link is the one-click way out. --}}
                <div class="px-5 py-3">
                    <div class="flex items-center gap-3">
                        <label for="epic-jira" class="w-20 shrink-0 text-[13px] text-zinc-500 dark:text-zinc-400">Jira epic</label>
                        <flux:input id="epic-jira" type="url" size="sm" class="flex-1 min-w-0"
                                    placeholder="Paste Jira link" wire:model.live.debounce.600ms="jira_epic_url" />
                        @if($epic->jira_epic_url)
                        <x-atlassian-link :url="$epic->jira_epic_url" kind="jira" />
                        @endif
                    </div>
                    <flux:error name="jira_epic_url" />
                </div>

                <div class="px-5 py-3">
                    <div class="flex items-center gap-3">
                        <label for="epic-jpd" class="w-20 shrink-0 text-[13px] text-zinc-500 dark:text-zinc-400">JPD idea</label>
                        <flux:input id="epic-jpd" type="url" size="sm" class="flex-1 min-w-0"
                                    placeholder="Paste idea link" wire:model.live.debounce.600ms="jpd_idea_url" />
                        @if($epic->jpd_idea_url)
                        <x-atlassian-link :url="$epic->jpd_idea_url" kind="idea" />
                        @endif
                    </div>
                    <flux:error name="jpd_idea_url" />
                </div>

                <div class="px-5 py-3.5 flex items-center justify-between gap-3">
                    <span class="min-w-0">
                        <span class="block text-[13px] text-zinc-500 dark:text-zinc-400">Recurring</span>
                        <span class="block text-[11px] text-zinc-400 dark:text-zinc-500">Re-plans into each new quarter</span>
                    </span>
                    <flux:switch wire:model.live="is_recurring" />
                </div>
            </div>

            <div class="px-1">
                <flux:button type="button" size="sm" variant="subtle" icon="trash"
                             class="text-red-600 dark:text-red-400"
                             wire:click="$set('confirmingDeletion', true)">
                    Delete epic
                </flux:button>
            </div>
        </aside>
    </div>

    <flux:modal wire:model="confirmingDeletion" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">Delete this epic?</flux:heading>
            <flux:text>
                This removes the epic along with its quarter plans and every allocation against it.
                This cannot be undone.
            </flux:text>
            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="$set('confirmingDeletion', false)">Cancel</flux:button>
                <flux:button variant="danger" wire:click="delete">Delete Epic</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
