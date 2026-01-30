<?php

use App\Models\Category;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('components.layouts.app.sidebar')] class extends Component
{
    #[Validate('required|string|max:50')]
    public string $name = '';

    #[Validate('nullable|string')]
    public string $description = '';

    #[Validate('required|string|max:7')]
    public string $color = '#6B7280';

    public function save(): void
    {
        $this->authorize('create', Category::class);

        $this->validate();

        $team = auth()->user()->currentTeam;

        // Check for duplicate name within team
        if ($team->categories()->where('name', $this->name)->exists()) {
            $this->addError('name', 'A category with this name already exists.');
            return;
        }

        // Get the next sort order
        $maxSortOrder = $team->categories()->max('sort_order') ?? -1;

        Category::create([
            'team_id' => $team->id,
            'name' => $this->name,
            'description' => $this->description,
            'color' => $this->color,
            'sort_order' => $maxSortOrder + 1,
            'is_default' => false,
        ]);

        $this->redirect('/categories', navigate: true);
    }
};
?>

<div class="max-w-3xl">
        <form wire:submit="save">
            <div class="pt-8 pb-4">
                <flux:button href="/categories" variant="ghost" icon="arrow-left" wire:navigate class="mb-3">Back to Categories</flux:button>
            </div>

            <h1 class="mb-6">Create Category</h1>

            <flux:card class="space-y-6">
                <flux:field>
                    <flux:label>Name</flux:label>
                    <flux:input wire:model="name" placeholder="e.g., Growth, Tech Debt, Run the Business" />
                    <flux:error name="name" />
                </flux:field>

                <flux:field>
                    <flux:label>Description</flux:label>
                    <flux:textarea wire:model="description" placeholder="Describe what type of work belongs in this category..." rows="3" />
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
                    <flux:button type="submit" variant="primary">Create Category</flux:button>
                    <flux:button href="/categories" variant="ghost" wire:navigate>Cancel</flux:button>
                </div>
            </flux:card>
        </form>

</div>
