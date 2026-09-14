<?php

use App\Models\Team;
use App\Models\User;
use Laravel\Jetstream\Events\TeamMemberRemoved;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app.header')] class extends Component
{
    public Team $team;

    public ?int $removingUserId = null;

    public function mount(Team $team): void
    {
        $this->team = $team;
    }

    public function confirmRemove(int $userId): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 404);

        if ($userId === $this->team->user_id) {
            Flux::toast(variant: 'danger', text: 'The owner cannot be removed from their own team.');

            return;
        }

        $this->removingUserId = $userId;
    }

    public function remove(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 404);

        $member = User::findOrFail($this->removingUserId);

        if ($member->id === $this->team->user_id) {
            abort(403);
        }

        // Straight to the model rather than Jetstream's RemoveTeamMember
        // action, which authorises against team roles the admin may not hold.
        $this->team->removeUser($member);
        TeamMemberRemoved::dispatch($this->team, $member);

        $this->removingUserId = null;

        Flux::toast("Removed {$member->email} from {$this->team->name}.");
    }

    public function with(): array
    {
        $this->team->loadCount(['squads', 'engineers', 'epics']);

        $members = $this->team->allUsers()->sortBy('name')->values();

        return [
            'members' => $members,
            'removingUser' => $this->removingUserId ? $members->firstWhere('id', $this->removingUserId) : null,
        ];
    }
};
?>

<div>
    <x-admin.layout :heading="$team->name" :subheading="__('Owned by :owner', ['owner' => $team->owner->name])">
        <div class="mb-6 flex flex-wrap gap-2">
            <flux:badge color="zinc">{{ $team->squads_count }} {{ Str::plural('squad', $team->squads_count) }}</flux:badge>
            <flux:badge color="zinc">{{ $team->engineers_count }} {{ Str::plural('engineer', $team->engineers_count) }}</flux:badge>
            <flux:badge color="zinc">{{ $team->epics_count }} {{ Str::plural('epic', $team->epics_count) }}</flux:badge>
            @if ($team->personal_team)
                <flux:badge color="zinc">Personal team</flux:badge>
            @endif
            <flux:badge color="zinc">Created {{ $team->created_at->toFormattedDateString() }}</flux:badge>
        </div>

        <flux:heading>{{ __('Members') }}</flux:heading>
        <flux:table class="mt-3">
            <flux:table.columns>
                <flux:table.column>Member</flux:table.column>
                <flux:table.column>Role</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($members as $member)
                    <flux:table.row :key="$member->id" data-test="member-row-{{ $member->id }}">
                        <flux:table.cell>
                            <div class="flex items-center gap-3">
                                <flux:avatar size="sm" :src="$member->profile_photo_path ? $member->profile_photo_url : null" :name="$member->name" :initials="$member->initials()" />
                                <div class="min-w-0">
                                    <flux:heading class="truncate">{{ $member->name }}</flux:heading>
                                    <flux:text class="truncate">{{ $member->email }}</flux:text>
                                </div>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($member->id === $team->user_id)
                                <flux:badge size="sm" color="blue" inset="top bottom">Owner</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc" inset="top bottom">{{ $member->membership->role ?? 'member' }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            @if ($member->id !== $team->user_id)
                                <flux:button variant="ghost" size="sm" icon="user-minus" inset="top bottom" wire:click="confirmRemove({{ $member->id }})" data-test="remove-member-{{ $member->id }}">
                                    Remove
                                </flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <flux:modal wire:model="removingUserId" class="max-w-md">
            @if ($removingUser)
                <div class="space-y-4">
                    <flux:heading size="lg">Remove {{ $removingUser->name }} from {{ $team->name }}?</flux:heading>
                    <flux:text>
                        They keep their account and can be invited back. Their comments on this
                        team's epics stay in place.
                    </flux:text>
                    <div class="flex justify-end gap-3">
                        <flux:button variant="ghost" wire:click="$set('removingUserId', null)">Cancel</flux:button>
                        <flux:button variant="danger" wire:click="remove" data-test="confirm-remove-member">Remove member</flux:button>
                    </div>
                </div>
            @endif
        </flux:modal>
    </x-admin.layout>
</div>
