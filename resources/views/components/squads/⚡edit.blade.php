<?php

use App\Models\Squad;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('components.layouts.app.sidebar')] class extends Component
{
    public Squad $squad;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string')]
    public string $description = '';

    #[Validate('required|string')]
    public string $color = '';

    public function mount(Squad $squad): void
    {
        $this->authorize('update', $squad);
        
        $this->squad = $squad;
        $this->name = $squad->name;
        $this->description = $squad->description ?? '';
        $this->color = $squad->color;
    }

    public function save(): void
    {
        $this->authorize('update', $this->squad);
        
        $this->validate();

        $this->squad->update([
            'name' => $this->name,
            'description' => $this->description,
            'color' => $this->color,
        ]);

        $this->redirect('/squads', navigate: true);
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->squad);
        
        $this->squad->delete();

        $this->redirect('/squads', navigate: true);
    }
};
?>

<div class="max-w-3xl">

        <form wire:submit="save" >
            <div class="pt-8 pb-4">
                <flux:button href="/squads" variant="ghost" icon="arrow-left" wire:navigate class="mb-3">Back to Squads</flux:button>
            </div>

            <h1 class="mb-6">Edit Squad</h1>

            <flux:card class="space-y-6">
                <flux:field>
                    <flux:label>Name</flux:label>
                    <flux:input wire:model="name" placeholder="e.g., Frontend, Backend, Mobile" />
                    <flux:error name="name" />
                </flux:field>

                <flux:field>
                    <flux:label>Description</flux:label>
                    <flux:textarea wire:model="description" placeholder="Describe what this squad focuses on..." rows="3" />
                    <flux:error name="description" />
                </flux:field>

                <flux:color-picker wire:model.live="color" label="Color" placeholder="#6B7280" />

                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <flux:button type="submit" variant="primary">Save Changes</flux:button>
                        <flux:button href="/squads" variant="ghost" wire:navigate>Cancel</flux:button>
                    </div>
                    <flux:button wire:click="delete" wire:confirm="Are you sure you want to delete this squad?" variant="danger">Delete</flux:button>
                </div>
            </flux:card>
        </form>
    
</div>