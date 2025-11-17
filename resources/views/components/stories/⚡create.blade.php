<?php

use App\Models\Epic;
use App\Models\Squad;
use App\Models\Status;
use App\Models\Story;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('components.layouts.app.sidebar')] class extends Component
{
    public Epic $epic;

    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('nullable|string')]
    public string $description = '';

    #[Validate('required|exists:squads,id')]
    public string $squad_id = '';

    #[Validate('required|exists:statuses,id')]
    public string $status_id = '';

    #[Validate('nullable|date')]
    public string $start_date = '';

    #[Validate('nullable|date|after_or_equal:start_date')]
    public string $end_date = '';

    public function mount(Epic $epic): void
    {
        $this->epic = $epic;
        $this->status_id = Status::where('slug', 'not-started')->first()?->id ?? '';
        $this->start_date = $epic->start_date?->format('Y-m-d') ?? '';
        $this->end_date = $epic->end_date?->format('Y-m-d') ?? '';
    }

    public function save(): void
    {
        $this->authorize('create', Story::class);
        
        $this->validate();

        Story::create([
            'epic_id' => $this->epic->id,
            'squad_id' => $this->squad_id,
            'status_id' => $this->status_id,
            'title' => $this->title,
            'description' => $this->description,
            'start_date' => $this->start_date ?: null,
            'end_date' => $this->end_date ?: null,
        ]);

        $this->redirect("/epics/{$this->epic->id}/edit", navigate: true);
    }

    public function with(): array
    {
        return [
            'statuses' => Status::orderBy('order')->get(),
            'squads' => $this->epic->squads,
        ];
    }
};
?>

<div>
    <flux:main>
        <form wire:submit="save" class="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8 py-6">
            <div class="mb-6">
                <flux:button href="/epics/{{ $epic->id }}/edit" variant="ghost" icon="arrow-left" wire:navigate>Back to Epic</flux:button>
            </div>

            <flux:heading size="xl" class="mb-2">Create Story</flux:heading>
            <flux:text class="mb-6">For epic: {{ $epic->title }}</flux:text>

            <flux:card class="space-y-6">
                <flux:field>
                    <flux:label>Title</flux:label>
                    <flux:input wire:model="title" placeholder="e.g., Design API endpoints" />
                    <flux:error name="title" />
                </flux:field>

                <flux:field>
                    <flux:label>Description</flux:label>
                    <flux:textarea wire:model="description" placeholder="Describe this story..." rows="3" />
                    <flux:error name="description" />
                </flux:field>

                <flux:field>
                    <flux:label>Squad</flux:label>
                    <flux:select wire:model="squad_id">
                        <option value="">Select a squad...</option>
                        @foreach($squads as $squad)
                            <option value="{{ $squad->id }}">{{ $squad->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="squad_id" />
                </flux:field>

                <flux:field>
                    <flux:label>Status</flux:label>
                    <flux:select wire:model="status_id">
                        @foreach($statuses as $status)
                            <option value="{{ $status->id }}">{{ $status->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="status_id" />
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
                    <flux:button type="submit" variant="primary">Create Story</flux:button>
                    <flux:button href="/epics/{{ $epic->id }}/edit" variant="ghost" wire:navigate>Cancel</flux:button>
                </div>
            </flux:card>
        </form>
    </flux:main>
</div>
