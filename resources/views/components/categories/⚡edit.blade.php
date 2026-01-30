<?php

use App\Models\Category;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('components.layouts.app.sidebar')] class extends Component
{
    public Category $category;

    #[Validate('required|string|max:50')]
    public string $name = '';

    #[Validate('nullable|string')]
    public string $description = '';

    #[Validate('required|string|max:7')]
    public string $color = '';

    public function mount(Category $category): void
    {
        $this->authorize('update', $category);

        $this->category = $category;
        $this->name = $category->name;
        $this->description = $category->description ?? '';
        $this->color = $category->color ?? '#6B7280';
    }

    public function save(): void
    {
        $this->authorize('update', $this->category);

        $this->validate();

        $team = auth()->user()->currentTeam;

        // Check for duplicate name within team (excluding current category)
        if ($team->categories()->where('name', $this->name)->where('id', '!=', $this->category->id)->exists()) {
            $this->addError('name', 'A category with this name already exists.');
            return;
        }

        $this->category->update([
            'name' => $this->name,
            'description' => $this->description,
            'color' => $this->color,
        ]);

        $this->redirect('/categories', navigate: true);
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->category);

        if ($this->category->is_default) {
            session()->flash('error', 'Cannot delete the default category.');
            return;
        }

        // Move epics to the default category before deleting
        $defaultCategory = auth()->user()->currentTeam->categories()->default()->first();
        if ($defaultCategory) {
            $this->category->epics()->update(['category_id' => $defaultCategory->id]);
        }

        $this->category->delete();

        $this->redirect('/categories', navigate: true);
    }
};
?>

<div class="max-w-3xl">

        <form wire:submit="save" >
            <div class="pt-8 pb-4">
                <flux:button href="/categories" variant="ghost" icon="arrow-left" wire:navigate class="mb-3">Back to Categories</flux:button>
            </div>

            <h1 class="mb-6">Edit Category</h1>

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

                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <flux:button type="submit" variant="primary">Save Changes</flux:button>
                        <flux:button href="/categories" variant="ghost" wire:navigate>Cancel</flux:button>
                    </div>
                    @if(!$category->is_default)
                        <flux:button wire:click="delete" wire:confirm="Are you sure you want to delete this category? All epics in this category will be moved to the default category." variant="danger">Delete</flux:button>
                    @else
                        <flux:text class="text-sm text-zinc-500">Default category cannot be deleted</flux:text>
                    @endif
                </div>
            </flux:card>
        </form>

</div>
