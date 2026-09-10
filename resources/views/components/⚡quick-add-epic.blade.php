<?php

use App\Models\Epic;
use App\Models\Status;
use App\Support\Quarter;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The "Add epic" modal the matrix and the epics list share.
 *
 * Just a title and a priority: enough to capture the thought. The epic
 * lands in the default status and the current quarter, and the matrix
 * quadrant its priority implies; everything else is on the epic's page.
 *
 * Opened by any `flux:modal.trigger name="add-epic"` on the page. Tells the
 * page it lives on with an `epic-added` event so the list can re-render.
 */
new class extends Component
{
    public string $title = '';

    public string $priority = 'medium';

    public function save(): void
    {
        $this->authorize('create', Epic::class);

        $this->validate([
            'title' => 'required|string|max:255',
            'priority' => 'required|in:low,medium,high,critical',
        ], [
            'title.required' => 'Give it a name.',
        ]);

        $team = Auth::user()->currentTeam;
        $quarter = Quarter::current();
        $status = Status::defaultFor($team);

        Epic::create([
            'team_id' => $team->id,
            'status_id' => $status?->id,
            'board_order' => $status ? ((int) $status->epics()->max('board_order')) + 1 : 0,
            'title' => $this->title,
            'priority' => $this->priority,
            'start_date' => $quarter->start(),
            'end_date' => $quarter->end(),
        ]);

        $this->reset('title', 'priority');
        $this->resetValidation();

        Flux::modal('add-epic')->close();
        Flux::toast(variant: 'success', text: __('Added.'));

        $this->dispatch('epic-added');
    }
};
?>

<flux:modal name="add-epic" class="max-w-md">
    <form wire:submit="save" class="space-y-6">
        <div>
            <flux:heading size="lg">Add an epic</flux:heading>
            <flux:subheading>Just enough to capture it. Description, squads and dates live on the epic's page.</flux:subheading>
        </div>

        <flux:input wire:model="title" label="Title" placeholder="What is it?" autofocus />

        <flux:select variant="listbox" wire:model="priority" label="Priority">
            <flux:select.option value="low">
                <div class="flex items-center gap-2"><flux:icon.chevron-down variant="micro" class="text-blue-600 dark:text-blue-400" /> Low</div>
            </flux:select.option>
            <flux:select.option value="medium">
                <div class="flex items-center gap-2"><flux:icon.equal variant="micro" class="text-amber-600 dark:text-amber-400" /> Medium</div>
            </flux:select.option>
            <flux:select.option value="high">
                <div class="flex items-center gap-2"><flux:icon.chevron-up variant="micro" class="text-orange-600 dark:text-orange-400" /> High</div>
            </flux:select.option>
            <flux:select.option value="critical">
                <div class="flex items-center gap-2"><flux:icon.chevrons-up variant="micro" class="text-red-600 dark:text-red-400" /> Critical</div>
            </flux:select.option>
        </flux:select>

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="filled">Cancel</flux:button>
            </flux:modal.close>
            <flux:button type="submit" variant="primary" icon="plus">Add epic</flux:button>
        </div>
    </form>
</flux:modal>
