<?php

use App\Models\Epic;
use App\Models\Status;
use App\Models\EpicQuarterPlan;
use App\Support\Quarter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('components.layouts.app.sidebar')] class extends Component
{
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

    /** Quarter the epic is being planned into, as "2026-Q3". */
    public string $quarter = '';

    #[Validate('nullable|array')]
    public array $squad_ids = [];

    /** squadId => planned points */
    public array $planned_points = [];

    public function mount(): void
    {
        $team = Auth::user()->currentTeam;

        $this->category_id = (string) ($team->categories()->default()->first()?->id ?? '');
        $this->status_id = (string) (Status::defaultFor($team)?->id ?? '');
        $this->quarter = Quarter::current()->key();
    }

    public function updatedSquadIds(): void
    {
        foreach ($this->squad_ids as $squadId) {
            $this->planned_points[$squadId] ??= 25;
        }
    }

    public function save(): void
    {
        $this->authorize('create', Epic::class);
        $this->validate();

        $quarter = Quarter::parse($this->quarter);

        DB::transaction(function () use ($quarter) {
            $epic = Epic::create([
                'team_id' => Auth::user()->currentTeam->id,
                'category_id' => $this->category_id ?: null,
                'title' => $this->title,
                'description' => $this->description,
                'status_id' => $this->status_id ?: null,
                'priority' => $this->priority,
                'start_date' => $this->start_date ?: null,
                'end_date' => $this->end_date ?: null,
                'is_recurring' => $this->is_recurring,
            ]);

            foreach ($this->squad_ids as $squadId) {
                EpicQuarterPlan::create([
                    'epic_id' => $epic->id,
                    'squad_id' => $squadId,
                    'year' => $quarter->year,
                    'quarter' => $quarter->quarter,
                    'planned_points' => $this->planned_points[$squadId] ?: null,
                ]);
            }
        });

        $this->redirect('/epics', navigate: true);
    }

    public function with(): array
    {
        $team = Auth::user()->currentTeam;

        return [
            'squads' => $team->squads()->ordered()->get(),
            'categories' => $team->categories()->ordered()->get(),
            'statuses' => $team->statuses()->ordered()->get(),
            'quarters' => Quarter::current()->previous()->through(9),
        ];
    }
};
?>

<div class="max-w-3xl">
    <div class="pt-8 pb-10">
        <h1>Create Epic</h1>
        <flux:text class="mt-1">Describe the work, then plan it into a squad's quarter.</flux:text>
    </div>

    <form wire:submit="save" class="space-y-6">
        <flux:card class="space-y-6">
            <flux:input wire:model="title" label="Title" placeholder="Smart Charging Scheduler" required />

            <flux:textarea wire:model="description" label="Description" rows="3"
                           placeholder="What is this work, and why now?" />

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <flux:select wire:model="status_id" label="Status">
                    @foreach($statuses as $status)
                    <flux:select.option value="{{ $status->id }}">{{ $status->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="priority" label="Priority">
                    <flux:select.option value="low">Low</flux:select.option>
                    <flux:select.option value="medium">Medium</flux:select.option>
                    <flux:select.option value="high">High</flux:select.option>
                    <flux:select.option value="critical">Critical</flux:select.option>
                </flux:select>

                <flux:select wire:model="category_id" label="Category" placeholder="None">
                    @foreach($categories as $category)
                    <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <flux:input type="date" wire:model="start_date" label="Start date" />
                <flux:input type="date" wire:model="end_date" label="End date" />
            </div>

            <flux:checkbox wire:model="is_recurring" label="Recurring"
                           description="Re-plan this epic into each new quarter automatically." />
        </flux:card>

        <flux:card class="space-y-6">
            <div>
                <flux:heading size="lg">Planning</flux:heading>
                <flux:text class="mt-1">
                    Squads carry no capacity of their own — it comes from the engineers on them.
                    The estimate here is what you think the work will take.
                </flux:text>
            </div>

            <flux:select wire:model="quarter" label="Quarter">
                @foreach($quarters as $option)
                <flux:select.option value="{{ $option->key() }}">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select multiple variant="listbox" wire:model.live="squad_ids"
                         label="Squads" placeholder="Select squads">
                @foreach($squads as $squad)
                <flux:select.option value="{{ $squad->id }}">{{ $squad->name }}</flux:select.option>
                @endforeach
            </flux:select>

            @if(! empty($squad_ids))
            <div class="space-y-3">
                @foreach($squads->whereIn('id', $squad_ids) as $squad)
                <div class="flex items-center gap-4 rounded-lg border border-zinc-200 dark:border-zinc-700 px-4 py-3">
                    <span class="inline-flex items-center gap-2 min-w-0 flex-1">
                        <div class="h-2.5 w-2.5 rounded-full shrink-0" style="background-color: {{ $squad->color }}"></div>
                        <flux:text class="font-medium truncate">{{ $squad->name }}</flux:text>
                    </span>
                    <flux:input type="number" min="0" class="w-32"
                                wire:model="planned_points.{{ $squad->id }}"
                                placeholder="Points" />
                </div>
                @endforeach
            </div>
            @endif
        </flux:card>

        <div class="flex items-center gap-3">
            <flux:button type="submit" variant="primary">Create Epic</flux:button>
            <flux:button href="/epics" variant="ghost" wire:navigate>Cancel</flux:button>
        </div>
    </form>
</div>
