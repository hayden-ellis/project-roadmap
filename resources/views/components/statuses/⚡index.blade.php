<?php

use App\Models\Status;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The team's board columns, edited in place.
 *
 * Order here is the order on the Now board, so the list is the board: drag a
 * row and the column moves. Everything writes as you change it, matching the
 * epic page -- there is no save button anywhere in Manage.
 */
new #[Layout('components.layouts.app.sidebar')] class extends Component
{
    /** Status being renamed / recoloured, or null when the list is at rest. */
    public ?int $editingId = null;

    public string $name = '';

    public string $color = '#71717A';

    public string $description = '';

    public bool $is_complete = false;

    public bool $requires_reason = false;

    public bool $creating = false;

    public ?int $confirmingDeletionId = null;

    /** Where epics move when their column is deleted. */
    public string $reassignTo = '';

    private const PALETTE = [
        '#71717A', '#10B981', '#F59E0B', '#3B82F6', '#8B5CF6',
        '#EC4899', '#EF4444', '#14B8A6', '#F97316', '#6366F1',
    ];

    public function edit(int $statusId): void
    {
        $status = $this->teamStatus($statusId);

        $this->editingId = $status->id;
        $this->creating = false;
        $this->name = $status->name;
        $this->color = $status->color;
        $this->description = $status->description ?? '';
        $this->is_complete = $status->is_complete;
        $this->requires_reason = $status->requires_reason;
    }

    public function cancel(): void
    {
        $this->editingId = null;
        $this->creating = false;
        $this->resetErrorBag();
    }

    public function startCreating(): void
    {
        $this->editingId = null;
        $this->creating = true;
        $this->name = '';
        $this->color = self::PALETTE[count($this->statuses()) % count(self::PALETTE)];
        $this->description = '';
        $this->is_complete = false;
        $this->requires_reason = false;
    }

    public function create(): void
    {
        $this->authorize('create', Status::class);

        $team = Auth::user()->currentTeam;

        $this->validate([
            'name' => 'required|string|max:40|unique:statuses,name,NULL,id,team_id,'.$team->id,
            'color' => 'required|string|max:9',
            'description' => 'nullable|string|max:120',
        ]);

        $status = $team->statuses()->create([
            'name' => $this->name,
            'color' => $this->color,
            'description' => $this->description ?: null,
            'is_default' => $team->statuses()->count() === 0,
            'is_complete' => $this->is_complete,
            'requires_reason' => $this->requires_reason,
        ]);

        $this->creating = false;
        $this->edit($status->id);
    }

    /** Inline edits save on change, one field at a time. */
    public function updated(string $property): void
    {
        if (! $this->editingId || $this->creating) {
            return;
        }

        if (! in_array($property, ['name', 'color', 'description', 'is_complete', 'requires_reason'], true)) {
            return;
        }

        $status = $this->teamStatus($this->editingId);

        if ($property === 'name') {
            $this->validate([
                'name' => 'required|string|max:40|unique:statuses,name,'.$status->id.',id,team_id,'.$status->team_id,
            ]);
        }

        if ($property === 'description') {
            $this->validate(['description' => 'nullable|string|max:120']);
        }

        $status->update([
            $property => $property === 'description' ? ($this->description ?: null) : $this->{$property},
        ]);

        $this->dispatch('status-saved');
    }

    public function makeDefault(int $statusId): void
    {
        $status = $this->teamStatus($statusId);

        DB::transaction(function () use ($status) {
            $status->team->statuses()->update(['is_default' => false]);
            $status->update(['is_default' => true]);
        });

        $this->dispatch('status-saved');
    }

    /** Drag-and-drop reordering; the board follows this order. */
    public function sort(int $item, int $position): void
    {
        $this->teamStatus($item)->move($position);

        $this->dispatch('status-saved');
    }

    public function confirmDeletion(int $statusId): void
    {
        $status = $this->teamStatus($statusId);

        $this->confirmingDeletionId = $status->id;
        $this->reassignTo = (string) ($this->statuses()
            ->firstWhere(fn ($s) => $s->id !== $status->id)?->id ?? '');
    }

    /**
     * Deleting a column must not take its epics with it -- they move to
     * whichever column was chosen, or fall out of any column if none is left.
     */
    public function delete(): void
    {
        $status = $this->teamStatus($this->confirmingDeletionId);
        $this->authorize('delete', $status);

        $target = $this->reassignTo ? $this->teamStatus((int) $this->reassignTo) : null;

        DB::transaction(function () use ($status, $target) {
            $status->epics()->update(['status_id' => $target?->id]);

            if ($status->is_default && $target) {
                $target->update(['is_default' => true]);
            }

            $status->delete();
        });

        $this->confirmingDeletionId = null;
        $this->editingId = null;
        $this->dispatch('status-saved');
    }

    private function teamStatus(?int $statusId): Status
    {
        abort_if($statusId === null, 404);

        return Status::where('team_id', Auth::user()->currentTeam->id)
            ->findOr($statusId, fn () => abort(403));
    }

    /** @return \Illuminate\Support\Collection<int, Status> */
    private function statuses()
    {
        return Auth::user()->currentTeam->statuses()->ordered()->get();
    }

    public function with(): array
    {
        $statuses = Auth::user()->currentTeam->statuses()
            ->withCount('epics')
            ->ordered()
            ->get();

        return [
            'statuses' => $statuses,
            'palette' => self::PALETTE,
            'totalEpics' => $statuses->sum('epics_count'),
            'deleting' => $this->confirmingDeletionId
                ? $statuses->firstWhere('id', $this->confirmingDeletionId)
                : null,
        ];
    }
};
?>

@php
    $micro = 'text-[10px] font-semibold uppercase tracking-[0.14em] text-zinc-400 dark:text-zinc-500';
    $panel = 'rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900';
@endphp

<div class="max-w-4xl"
     x-data="{ saved: false, timer: null }"
     x-on:status-saved.window="saved = true; clearTimeout(timer); timer = setTimeout(() => saved = false, 1600)">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pt-8 pb-6">
        <div>
            <h1>Statuses</h1>
            <flux:text class="mt-1">The columns on your board, in the order they appear.</flux:text>
        </div>

        <div class="flex items-center gap-3">
            <span x-cloak x-show="saved" x-transition.opacity.duration.150ms
                  class="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-600 dark:text-emerald-400">
                <flux:icon.check variant="micro" class="size-3.5" />Saved
            </span>
            <flux:button icon="plus" size="sm" wire:click="startCreating">New status</flux:button>
        </div>
    </div>

    @if($statuses->isEmpty() && ! $creating)
    <div class="{{ $panel }}">
        <div class="text-center py-12 px-6">
            <flux:icon.view-columns class="mx-auto h-12 w-12 text-zinc-400" />
            <flux:heading size="lg" class="mt-4">No statuses yet</flux:heading>
            <flux:text class="mt-2">Add the columns your team works in — Backlog, In progress, Paused, whatever fits.</flux:text>
            <flux:button variant="primary" class="mt-6" wire:click="startCreating">Add the first status</flux:button>
        </div>
    </div>
    @else

    {{-- The board, at the size you can take in at once. Reordering the list
         below moves these, so you can see the shape before you commit. --}}
    @if($statuses->isNotEmpty())
    <div class="{{ $panel }} p-5 mb-5">
        <div class="{{ $micro }} mb-3">Board preview</div>
        <div class="flex gap-2 overflow-x-auto [contain:paint] -mx-1 px-1">
            @foreach($statuses as $status)
            <div class="flex-1 min-w-[7.5rem] rounded-lg border border-zinc-200 dark:border-zinc-700 p-2.5"
                 wire:key="preview-{{ $status->id }}">
                <div class="flex items-center gap-1.5 min-w-0">
                    <span class="size-2 rounded-full shrink-0" style="background-color: {{ $status->color }}"></span>
                    <span class="text-[11px] font-semibold truncate text-zinc-700 dark:text-zinc-300">{{ $status->name }}</span>
                </div>
                <div class="mt-2 space-y-1">
                    @for($i = 0; $i < min($status->epics_count, 3); $i++)
                    <div class="h-1.5 rounded-full" style="background-color: {{ $status->color }}; opacity: {{ 0.5 - $i * 0.12 }}"></div>
                    @endfor
                    @if($status->epics_count === 0)
                    <div class="h-1.5 rounded-full bg-zinc-100 dark:bg-zinc-800"></div>
                    @endif
                </div>
                <div class="mt-2 text-[10px] tabular-nums text-zinc-400">
                    {{ $status->epics_count }} {{ Str::plural('epic', $status->epics_count) }}
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    <div class="{{ $panel }} divide-y divide-zinc-100 dark:divide-zinc-800">
        <div x-sort="$wire.sort($item, $position)" class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @foreach($statuses as $status)
            <div x-sort:item="{{ $status->id }}" wire:key="status-{{ $status->id }}" class="group">

                <div class="flex items-center gap-3 px-4 py-3">
                    <div x-sort:handle class="cursor-grab active:cursor-grabbing text-zinc-300 dark:text-zinc-600 hover:text-zinc-500 shrink-0"
                         title="Drag to reorder">
                        <flux:icon.bars-3 variant="micro" class="size-4" />
                    </div>

                    <span class="size-3 rounded-full shrink-0" style="background-color: {{ $status->color }}"></span>

                    <button type="button" wire:click="{{ $editingId === $status->id ? 'cancel' : 'edit('.$status->id.')' }}"
                            class="flex-1 min-w-0 text-left">
                        <span class="flex items-center gap-2 min-w-0">
                            <span class="text-[15px] font-medium truncate text-zinc-900 dark:text-zinc-100">{{ $status->name }}</span>
                            @if($status->is_default)
                            <flux:badge size="sm" color="zinc">Default</flux:badge>
                            @endif
                            @if($status->is_complete)
                            <flux:badge size="sm" color="blue">Finished</flux:badge>
                            @endif
                            @if($status->requires_reason)
                            <flux:badge size="sm" color="amber">Asks why</flux:badge>
                            @endif
                        </span>
                        @if($status->description)
                        <span class="block text-xs mt-0.5 truncate text-zinc-500 dark:text-zinc-400">{{ $status->description }}</span>
                        @endif
                    </button>

                    <span class="text-[11px] tabular-nums text-zinc-400 shrink-0">
                        {{ $status->epics_count }}
                    </span>

                    <flux:button size="xs" variant="subtle"
                                 icon="{{ $editingId === $status->id ? 'chevron-up' : 'pencil-square' }}"
                                 wire:click="{{ $editingId === $status->id ? 'cancel' : 'edit('.$status->id.')' }}">
                        <span class="sr-only">Edit {{ $status->name }}</span>
                    </flux:button>
                </div>

                @if($editingId === $status->id)
                <div class="px-4 pb-4 pt-1 bg-zinc-50/70 dark:bg-zinc-800/30">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <flux:input size="sm" label="Name" wire:model.live.debounce.600ms="name" />
                        <flux:input size="sm" label="Description" placeholder="What belongs here"
                                    wire:model.live.debounce.600ms="description" />
                    </div>

                    <div class="mt-4">
                        <div class="{{ $micro }} mb-2">Colour</div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            @foreach($palette as $swatch)
                            <button type="button" wire:click="$set('color', '{{ $swatch }}')"
                                    class="size-6 rounded-full transition-transform hover:scale-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent
                                           {{ $color === $swatch ? 'ring-2 ring-offset-2 ring-zinc-900 dark:ring-white dark:ring-offset-zinc-900' : '' }}"
                                    style="background-color: {{ $swatch }}"
                                    title="{{ $swatch }}">
                                <span class="sr-only">Use {{ $swatch }}</span>
                            </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="mt-4 space-y-3">
                        <label class="flex items-start justify-between gap-4 cursor-pointer">
                            <span class="min-w-0">
                                <span class="block text-[13px] font-medium text-zinc-800 dark:text-zinc-200">Counts as finished</span>
                                <span class="block text-[11px] text-zinc-500 dark:text-zinc-400">
                                    Epics here drop out of planning and the roadmap.
                                </span>
                            </span>
                            <flux:switch wire:model.live="is_complete" />
                        </label>

                        <label class="flex items-start justify-between gap-4 cursor-pointer">
                            <span class="min-w-0">
                                <span class="block text-[13px] font-medium text-zinc-800 dark:text-zinc-200">Ask why on arrival</span>
                                <span class="block text-[11px] text-zinc-500 dark:text-zinc-400">
                                    Moving an epic here prompts for a reason you can read back later.
                                </span>
                            </span>
                            <flux:switch wire:model.live="requires_reason" />
                        </label>
                    </div>

                    <div class="mt-5 flex items-center justify-between gap-3">
                        @unless($status->is_default)
                        <flux:button size="xs" variant="subtle" wire:click="makeDefault({{ $status->id }})">
                            Make default
                        </flux:button>
                        @else
                        <flux:text class="text-xs">New epics land here.</flux:text>
                        @endunless

                        <flux:button size="xs" variant="subtle" icon="trash" class="text-red-600 dark:text-red-400"
                                     wire:click="confirmDeletion({{ $status->id }})">
                            Delete
                        </flux:button>
                    </div>
                </div>
                @endif
            </div>
            @endforeach
        </div>

        @if($creating)
        <div class="px-4 py-4 bg-zinc-50/70 dark:bg-zinc-800/30">
            <div class="{{ $micro }} mb-3">New status</div>
            <form wire:submit="create" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <flux:input size="sm" label="Name" placeholder="Backlog" wire:model="name" autofocus />
                    <flux:input size="sm" label="Description" placeholder="What belongs here" wire:model="description" />
                </div>

                <div>
                    <div class="{{ $micro }} mb-2">Colour</div>
                    <div class="flex flex-wrap items-center gap-1.5">
                        @foreach($palette as $swatch)
                        <button type="button" wire:click="$set('color', '{{ $swatch }}')"
                                class="size-6 rounded-full transition-transform hover:scale-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent
                                       {{ $color === $swatch ? 'ring-2 ring-offset-2 ring-zinc-900 dark:ring-white dark:ring-offset-zinc-900' : '' }}"
                                style="background-color: {{ $swatch }}">
                            <span class="sr-only">Use {{ $swatch }}</span>
                        </button>
                        @endforeach
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <flux:button type="submit" size="sm" variant="primary">Add status</flux:button>
                    <flux:button type="button" size="sm" variant="ghost" wire:click="cancel">Cancel</flux:button>
                </div>
            </form>
        </div>
        @endif
    </div>

    <flux:text class="text-xs mt-3">
        {{ $totalEpics }} {{ Str::plural('epic', $totalEpics) }} filed across {{ $statuses->count() }}
        {{ Str::plural('status', $statuses->count()) }}. Drag to reorder — the board follows this order.
    </flux:text>
    @endif

    <flux:modal :open="$confirmingDeletionId !== null" wire:model.self="confirmingDeletionId" class="max-w-md">
        @if($deleting)
        <div class="space-y-4">
            <flux:heading size="lg">Delete "{{ $deleting->name }}"?</flux:heading>

            @if($deleting->epics_count > 0)
            <flux:text>
                {{ $deleting->epics_count }} {{ Str::plural('epic', $deleting->epics_count) }}
                {{ $deleting->epics_count === 1 ? 'is' : 'are' }} in this status. Pick where they go.
            </flux:text>

            <flux:select wire:model="reassignTo" label="Move them to" placeholder="No status">
                @foreach($statuses->where('id', '!=', $deleting->id) as $option)
                <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
            @else
            <flux:text>Nothing is in this status, so nothing moves.</flux:text>
            @endif

            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="$set('confirmingDeletionId', null)">Cancel</flux:button>
                <flux:button variant="danger" wire:click="delete">Delete status</flux:button>
            </div>
        </div>
        @endif
    </flux:modal>
</div>
