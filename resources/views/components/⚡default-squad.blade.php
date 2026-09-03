<?php

use App\Support\DefaultSquad;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * Sits beside a page's squad filter. When the page is showing the user's
 * default squad it says so and offers to clear it; when the page is filtered
 * to some other single squad it offers to make that one the default. Any
 * other state renders nothing.
 */
new class extends Component
{
    /** The one squad the page is currently filtered to, or null. */
    #[Reactive]
    public ?int $selected = null;

    public function makeDefault(): void
    {
        $user = Auth::user();
        $squad = $this->selected ? $user->currentTeam->squads()->find($this->selected) : null;

        if (! $squad) {
            return;
        }

        DefaultSquad::set($user, $user->currentTeam, $squad);
    }

    public function clearDefault(): void
    {
        $user = Auth::user();

        DefaultSquad::set($user, $user->currentTeam, null);
    }

    public function with(): array
    {
        $user = Auth::user();
        $team = $user->currentTeam;

        return [
            'default' => DefaultSquad::for($user, $team),
            'selectedSquad' => $this->selected ? $team->squads()->find($this->selected) : null,
        ];
    }
};
?>

<div class="inline-flex items-center gap-1.5 text-xs whitespace-nowrap">
    @if($default && $selected === $default->id)
    <span class="inline-flex items-center gap-1.5 h-7 pl-2.5 pr-1 rounded-md bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300">
        <span class="size-1.5 rounded-full" style="background-color: {{ $default->color }}"></span>
        {{ $default->name }} is your default
        <flux:tooltip content="Stop starting on {{ $default->name }}">
            <flux:button wire:click="clearDefault" variant="ghost" size="xs" icon="x-mark" square class="ml-0.5" aria-label="Clear default squad" />
        </flux:tooltip>
    </span>
    @elseif($selectedSquad)
    <flux:button wire:click="makeDefault" variant="ghost" size="xs" icon="star">
        Make {{ $selectedSquad->name }} my default
    </flux:button>
    @endif
</div>
