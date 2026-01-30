<?php

use App\Models\Category;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app.sidebar')] class extends Component
{
    public function with(): array
    {
        return [
            'categories' => auth()->user()->currentTeam
                ->categories()
                ->withCount('epics')
                ->ordered()
                ->get(),
        ];
    }
};
?>

<div>

        <div>
            <div class="flex items-center justify-between pt-8 pb-10">
                <h1>Categories</h1>
                <flux:button href="/categories/create" icon="plus" wire:navigate>Create Category</flux:button>
            </div>

            @if($categories->isEmpty())
                <flux:card>
                    <div class="text-center py-12">
                        <flux:icon.tag class="mx-auto h-12 w-12 text-zinc-400" />
                        <flux:heading size="lg" class="mt-4">No categories yet</flux:heading>
                        <flux:text class="mt-2">Get started by creating your first category to organize your work.</flux:text>
                        <flux:button href="/categories/create" variant="primary" class="mt-6" wire:navigate>Create Category</flux:button>
                    </div>
                </flux:card>
            @else
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($categories as $category)
                        <flux:card href="/categories/{{ $category->id }}/edit" wire:navigate class="hover:shadow-lg transition-shadow cursor-pointer">
                            <div class="flex items-start gap-4">
                                <div class="h-12 w-12 rounded-lg flex-shrink-0" style="background-color: {{ $category->color ?? '#6B7280' }}"></div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <flux:heading size="lg" class="truncate">{{ $category->name }}</flux:heading>
                                        @if($category->is_default)
                                            <flux:badge color="zinc" size="sm">Default</flux:badge>
                                        @endif
                                    </div>
                                    @if($category->description)
                                        <flux:text class="mt-1 line-clamp-2">{{ $category->description }}</flux:text>
                                    @endif
                                    <flux:badge class="mt-3" color="zinc" size="sm">
                                        {{ $category->epics_count }} {{ Str::plural('epic', $category->epics_count) }}
                                    </flux:badge>
                                </div>
                            </div>
                        </flux:card>
                    @endforeach
                </div>
            @endif
        </div>

</div>
