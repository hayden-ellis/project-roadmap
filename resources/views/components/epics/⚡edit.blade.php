<?php

use App\Models\Epic;
use App\Models\Squad;
use App\Models\Status;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Illuminate\Support\Facades\Auth;

new #[Layout('components.layouts.app.sidebar')] class extends Component
{
    public Epic $epic;

    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('nullable|string')]
    public string $description = '';

    #[Validate('required|exists:statuses,id')]
    public string $status_id = '';

    #[Validate('required|in:low,medium,high,critical')]
    public string $priority = 'medium';

    #[Validate('nullable|date')]
    public string $start_date = '';

    #[Validate('nullable|date|after_or_equal:start_date')]
    public string $end_date = '';

    #[Validate('nullable|array')]
    public array $squad_ids = [];

    public array $squad_data = [];

    public function mount(Epic $epic): void
    {
        $this->authorize('update', $epic);
        
        $this->epic = $epic;
        $this->title = $epic->title;
        $this->description = $epic->description ?? '';
        $this->status_id = (string) $epic->status_id;
        $this->priority = $epic->priority ?? 'medium';
        $this->start_date = $epic->start_date?->format('Y-m-d') ?? '';
        $this->end_date = $epic->end_date?->format('Y-m-d') ?? '';
        $this->squad_ids = $epic->squads()->pluck('squads.id')->map(fn($id) => (string) $id)->toArray();
        
        // Load pivot data for each squad
        foreach ($epic->squads as $squad) {
            $this->squad_data[$squad->id] = [
                'start_date' => $squad->pivot->start_date ? \Carbon\Carbon::parse($squad->pivot->start_date)->format('Y-m-d') : '',
                'end_date' => $squad->pivot->end_date ? \Carbon\Carbon::parse($squad->pivot->end_date)->format('Y-m-d') : '',
                'story_points' => $squad->pivot->story_points ?? '',
            ];
        }
    }

    public function updatedSquadIds(): void
    {
        // When a squad is added, pre-populate with epic dates if they exist
        foreach ($this->squad_ids as $squadId) {
            if (!isset($this->squad_data[$squadId])) {
                $this->squad_data[$squadId] = [
                    'start_date' => $this->start_date,
                    'end_date' => $this->end_date,
                    'story_points' => '',
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

    public function save(): void
    {
        $this->authorize('update', $this->epic);
        
        $this->validate();

        $this->epic->update([
            'status_id' => $this->status_id,
            'priority' => $this->priority,
            'title' => $this->title,
            'description' => $this->description,
            'start_date' => $this->start_date ?: null,
            'end_date' => $this->end_date ?: null,
        ]);

        // Sync squads with pivot data
        $syncData = [];
        foreach ($this->squad_ids as $squadId) {
            $syncData[$squadId] = [
                'start_date' => $this->squad_data[$squadId]['start_date'] ?? null,
                'end_date' => $this->squad_data[$squadId]['end_date'] ?? null,
                'story_points' => $this->squad_data[$squadId]['story_points'] ?? null,
            ];
        }
        
        $this->epic->squads()->sync($syncData);

        $this->redirect('/epics', navigate: true);
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->epic);
        
        $this->epic->delete();

        $this->redirect('/epics', navigate: true);
    }

    public function with(): array
    {
        return [
            'statuses' => Status::orderBy('order')->get(),
            'squads' => Auth::user()->currentTeam->squads()->orderBy('name')->get(),
        ];
    }
};
?>

<div class="max-w-4xl">
        <form wire:submit="save">
            <div class="mb-6">
                <flux:button href="/epics" variant="ghost" icon="arrow-left" wire:navigate>Back to Epics</flux:button>
            </div>

            <flux:heading size="xl" class="mb-6">Edit Epic</flux:heading>

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

                <flux:field>
                    <div class="flex items-center justify-between mb-3">
                        <flux:label>Squads (Optional)</flux:label>
                        @if(!empty($squad_ids) && ($start_date || $end_date))
                            <flux:button 
                                wire:click="copyDatesToAllSquads" 
                                variant="ghost" 
                                size="xs"
                                icon="arrow-down">
                                Copy Epic Dates to All Squads
                            </flux:button>
                        @endif
                    </div>
                    
                    @if($squads->isEmpty())
                        <flux:callout icon="information-circle">
                            <flux:callout.text>
                                No squads created yet. You can <a href="/squads/create" wire:navigate class="underline font-medium">create a squad</a> to assign to this epic.
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
                                            <div class="grid grid-cols-2 gap-3">
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
                                                <flux:input type="number" wire:model="squad_data.{{ $squad->id }}.story_points" placeholder="e.g., 8" class="text-sm" />
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <flux:error name="squad_ids" />
                </flux:field>

                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <flux:button type="submit" variant="primary">Save Changes</flux:button>
                        <flux:button href="/epics" variant="ghost" wire:navigate>Cancel</flux:button>
                    </div>
                    <flux:button wire:click="delete" wire:confirm="Are you sure you want to delete this epic?" variant="danger">Delete</flux:button>
                </div>
            </flux:card>
        </form>

</div>
