<?php

use App\Models\Squad;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('components.layouts.app.sidebar')] class extends Component
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string')]
    public string $description = '';

    #[Validate('required|string')]
    public string $color = '#6B7280';

    public function save(): void
    {
        $this->authorize('create', Squad::class);
        
        $this->validate();

        Squad::create([
            'team_id' => auth()->user()->currentTeam->id,
            'name' => $this->name,
            'description' => $this->description,
            'color' => $this->color,
        ]);

        $this->redirect('/squads', navigate: true);
    }
};
?>

<div class="max-w-3xl">
        <form wire:submit="save">
            <div class="mb-6">
                <flux:button href="/squads" variant="ghost" icon="arrow-left" wire:navigate>Back to Squads</flux:button>
            </div>

            <flux:heading size="xl" class="mb-6">Create Squad</flux:heading>

            <flux:card class="space-y-6">
                <flux:field>
                    <flux:label>Name</flux:label>
                    <flux:input wire:model="name" placeholder="e.g., Charging, Pricing, Payments" />
                    <flux:error name="name" />
                </flux:field>

                <flux:field>
                    <flux:label>Description</flux:label>
                    <flux:textarea wire:model="description" placeholder="Describe what this squad focuses on..." rows="3" />
                    <flux:error name="description" />
                </flux:field>

                <flux:field>
                    <flux:label>Color</flux:label>
                    <div class="flex items-center gap-4">
                        <input type="color" wire:model.live="color" class="h-10 w-20 rounded border-zinc-300 dark:border-zinc-700" />
                        <flux:input wire:model="color" placeholder="#6B7280" class="flex-1" />
                    </div>
                    <flux:error name="color" />
                </flux:field>

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">Create Squad</flux:button>
                    <flux:button href="/squads" variant="ghost" wire:navigate>Cancel</flux:button>
                </div>
            </flux:card>
        </form>

</div>