<?php

use App\Models\Category;
use App\Models\Epic;
use App\Models\Squad;
use App\Models\Status;
use Illuminate\Support\Facades\Auth;
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

    #[Validate('nullable|date')]
    public string $start_date = '';

    #[Validate('nullable|date|after_or_equal:start_date')]
    public string $end_date = '';

    #[Validate('nullable|array')]
    public array $squad_ids = [];

    public array $squad_data = [];

    public function mount(): void
    {
        $this->status_id = Status::where('slug', 'not-started')->first()?->id ?? '';
        // Default to the team's default category
        $this->category_id = Auth::user()->currentTeam->categories()->default()->first()?->id ?? '';
    }

    public function updatedSquadIds(): void
    {
        // When a squad is added, pre-populate with epic dates if they exist
        foreach ($this->squad_ids as $squadId) {
            if (! isset($this->squad_data[$squadId])) {
                $this->squad_data[$squadId] = [
                    'start_date' => $this->start_date,
                    'end_date' => $this->end_date,
                    'story_points' => 0,
                ];
            }
        }
    }

    public function copyDatesToAllSquads(): void
    {
        // Copy epic dates to all selected squads
        foreach ($this->squad_ids as $squadId) {
            $this->squad_data[$squadId]['start_date'] = $this->start_date;
            $this->squad_data[$squadId]['end_date'] = $this->end_date;
        }
    }

    public function applyQuarterPreset(int $squadId, string $preset): void
    {
        if (! isset($this->squad_data[$squadId])) {
            $this->squad_data[$squadId] = [
                'start_date' => '',
                'end_date' => '',
                'story_points' => 0,
            ];
        }

        $now = \Carbon\Carbon::now();
        $currentQuarter = ceil($now->month / 3);
        $currentYear = $now->year;

        if ($preset === 'this-quarter') {
            $startMonth = ($currentQuarter - 1) * 3 + 1;
            $startDate = \Carbon\Carbon::create($currentYear, $startMonth, 1)->startOfMonth();
            $endDate = $startDate->copy()->addMonths(2)->endOfMonth();
        } else {
            // next-quarter
            if ($currentQuarter === 4) {
                $startMonth = 1;
                $year = $currentYear + 1;
            } else {
                $startMonth = ($currentQuarter * 3) + 1;
                $year = $currentYear;
            }
            $startDate = \Carbon\Carbon::create($year, $startMonth, 1)->startOfMonth();
            $endDate = $startDate->copy()->addMonths(2)->endOfMonth();
        }

        $this->squad_data[$squadId]['start_date'] = $startDate->format('Y-m-d');
        $this->squad_data[$squadId]['end_date'] = $endDate->format('Y-m-d');
    }

    public function save(): void
    {
        $this->authorize('create', Epic::class);

        $this->validate();

        $epic = Epic::create([
            'team_id' => Auth::user()->currentTeam->id,
            'status_id' => $this->status_id,
            'category_id' => $this->category_id ?: null,
            'priority' => $this->priority,
            'title' => $this->title,
            'description' => $this->description,
            'start_date' => $this->start_date ?: null,
            'end_date' => $this->end_date ?: null,
        ]);

        // Attach squads with pivot data
        $attachData = [];
        foreach ($this->squad_ids as $squadId) {
            $storyPoints = $this->squad_data[$squadId]['story_points'] ?? '';
            $attachData[$squadId] = [
                'start_date' => $this->squad_data[$squadId]['start_date'] ?? null,
                'end_date' => $this->squad_data[$squadId]['end_date'] ?? null,
                'story_points' => $storyPoints !== '' ? (int) $storyPoints : null,
            ];
        }

        $epic->squads()->attach($attachData);

        $this->redirect('/epics', navigate: true);
    }

    public function with(): array
    {
        return [
            'statuses' => Status::orderBy('order')->get(),
            'squads' => Auth::user()->currentTeam->squads()->orderBy('name')->get(),
            'categories' => Auth::user()->currentTeam->categories()->ordered()->get(),
        ];
    }
};
?>

<div class="max-w-7xl">
    <form wire:submit="save">
        <div class="pb-4">
            <flux:button href="/epics" variant="ghost" icon="arrow-left" wire:navigate class="mb-3">Back to Epics</flux:button>
        </div>

        <h1 class="mb-6">Create Epic</h1>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
            <!-- Main Form (2/3 width on large screens) -->
            <div class="xl:col-span-2">
                <flux:card class="space-y-6">
                    <flux:field>
                        <flux:label>Title</flux:label>
                        <flux:input wire:model="title" placeholder="e.g., Payment Gateway Integration" />
                        <flux:error name="title" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Description</flux:label>
                        <flux:textarea wire:model="description" placeholder="Describe this epic..." rows="4" />
                        <flux:error name="description" />
                    </flux:field>

                    <div class="grid grid-cols-2 gap-4">
                        <flux:field>
                            <flux:label>Status</flux:label>
                            <flux:select wire:model="status_id">
                                @foreach($statuses as $status)
                                    <option value="{{ $status->id }}">{{ $status->name }}</option>
                                @endforeach
                            </flux:select>
                            <flux:error name="status_id" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Priority</flux:label>
                            <flux:select wire:model="priority">
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </flux:select>
                            <flux:error name="priority" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>Category</flux:label>
                        <flux:select wire:model="category_id">
                            <option value="">Select a category</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:description>Categorize this epic by type of work</flux:description>
                        <flux:error name="category_id" />
                    </flux:field>

                    <div class="grid grid-cols-2 gap-4">
                        <flux:field>
                            <flux:label>Start Date</flux:label>
                            <flux:input type="date" wire:model="start_date" />
                            <flux:error name="start_date" />
                        </flux:field>

                        <flux:field>
                            <flux:label>End Date</flux:label>
                            <flux:input type="date" wire:model="end_date" />
                            <flux:error name="end_date" />
                        </flux:field>
                    </div>

                    <div class="flex items-center gap-3">
                        <flux:button type="submit" variant="primary">Create Epic</flux:button>
                        <flux:button href="/epics" variant="ghost" wire:navigate>Cancel</flux:button>
                    </div>
                </flux:card>
            </div>

            <!-- Squads Sidebar (1/3 width on large screens) -->
            <div class="xl:col-span-1">
                <flux:card>
                    <div class="flex items-center justify-between mb-4">
                        <flux:heading size="lg">Squads</flux:heading>
                        @if(!empty($squad_ids) && ($start_date || $end_date))
                            <flux:button 
                                wire:click="copyDatesToAllSquads" 
                                variant="ghost" 
                                size="xs"
                                icon="arrow-down">
                                Copy Dates
                            </flux:button>
                        @endif
                    </div>
                    
                    @if($squads->isEmpty())
                        <flux:callout icon="information-circle" class="text-sm">
                            <flux:callout.text>
                                No squads created yet. You can <a href="/squads/create" wire:navigate class="underline font-medium">create a squad first</a>.
                            </flux:callout.text>
                        </flux:callout>
                    @else
                        <div class="space-y-4">
                            @foreach($squads as $squad)
                                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                                    <label class="flex items-center gap-3 cursor-pointer mb-3">
                                        <input type="checkbox" wire:model.live="squad_ids" value="{{ $squad->id }}" class="rounded border-zinc-300 dark:border-zinc-700" />
                                        <div class="h-4 w-4 rounded" style="background-color: {{ $squad->color }}"></div>
                                        <span class="flex-1 font-medium">{{ $squad->name }}</span>
                                    </label>
                                    
                                    @if(in_array((string)$squad->id, $squad_ids))
                                        <div class="ml-7 space-y-3 pt-3 border-t border-zinc-200 dark:border-zinc-700">
                                            <!-- Quarter Presets -->
                                            <div class="flex flex-wrap items-center gap-2 mb-2">
                                                <flux:text class="text-xs text-zinc-600 dark:text-zinc-400 mr-1">Quick presets:</flux:text>
                                                <flux:button 
                                                    wire:click="applyQuarterPreset({{ $squad->id }}, 'this-quarter')" 
                                                    variant="ghost" 
                                                    size="xs"
                                                    class="h-6 text-xs">
                                                    This Q
                                                </flux:button>
                                                <flux:button 
                                                    wire:click="applyQuarterPreset({{ $squad->id }}, 'next-quarter')" 
                                                    variant="ghost" 
                                                    size="xs"
                                                    class="h-6 text-xs">
                                                    Next Q
                                                </flux:button>
                                            </div>
                                            <div class="space-y-2">
                                                <div>
                                                    <flux:label class="text-xs">Start Date</flux:label>
                                                    <flux:input type="date" wire:model="squad_data.{{ $squad->id }}.start_date" class="text-sm" />
                                                </div>
                                                <div>
                                                    <flux:label class="text-xs">End Date</flux:label>
                                                    <flux:input type="date" wire:model="squad_data.{{ $squad->id }}.end_date" class="text-sm" />
                                                </div>
                                            </div>
                                            <div>
                                                <flux:label class="text-xs">Story Points</flux:label>
                                                <div class="flex items-center gap-3 -mt-1">
                                                    <flux:slider 
                                                        wire:model="squad_data.{{ $squad->id }}.story_points" 
                                                        min="0" 
                                                        max="250" 
                                                        step="5" 
                                                    />
                                                    <flux:input 
                                                        wire:model="squad_data.{{ $squad->id }}.story_points" 
                                                        type="number" 
                                                        size="sm" 
                                                        class="max-w-20" 
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <flux:error name="squad_ids" />
                </flux:card>
            </div>
        </div>
    </form>
</div>
