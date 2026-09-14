<?php

use App\Models\User;
use Laravel\Jetstream\Contracts\DeletesUsers;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('components.layouts.app.header')] class extends Component
{
    #[Url]
    public string $search = '';

    public ?int $deletingUserId = null;

    public function revokeTokens(int $userId): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 404);

        $user = User::findOrFail($userId);
        $count = $user->tokens()->delete();

        Flux::toast("Revoked {$count} ".Str::plural('token', $count)." for {$user->email}.");
    }

    public function confirmDelete(int $userId): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 404);

        if ($userId === auth()->id()) {
            Flux::toast(variant: 'danger', text: 'You cannot delete your own account from here.');

            return;
        }

        $this->deletingUserId = $userId;
    }

    public function delete(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 404);

        $user = User::findOrFail($this->deletingUserId);

        if ($user->id === auth()->id()) {
            abort(403);
        }

        app(DeletesUsers::class)->delete($user);

        $this->deletingUserId = null;

        Flux::toast("Deleted {$user->email}.");
    }

    public function with(): array
    {
        $users = User::query()
            ->with(['ownedTeams', 'teams'])
            ->withCount('tokens')
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")))
            ->orderBy('name')
            ->get();

        return [
            'users' => $users,
            'deletingUser' => $this->deletingUserId ? $users->firstWhere('id', $this->deletingUserId) : null,
        ];
    }
};
?>

<div>
    <x-admin.layout :heading="__('Users')" :subheading="__('Everyone with an account, and the teams they belong to')">
        <div class="mb-4 max-w-sm">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search by name or email" clearable data-test="user-search" />
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>User</flux:table.column>
                <flux:table.column>Teams</flux:table.column>
                <flux:table.column>Security</flux:table.column>
                <flux:table.column>Tokens</flux:table.column>
                <flux:table.column>Last login</flux:table.column>
                <flux:table.column>Joined</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($users as $user)
                    <flux:table.row :key="$user->id" data-test="user-row-{{ $user->id }}">
                        <flux:table.cell>
                            <div class="flex items-center gap-3">
                                <flux:avatar size="sm" :src="$user->profile_photo_path ? $user->profile_photo_url : null" :name="$user->name" :initials="$user->initials()" />
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <flux:heading class="truncate">{{ $user->name }}</flux:heading>
                                        @if ($user->isSuperAdmin())
                                            <flux:badge size="sm" color="amber" inset="top bottom">Super admin</flux:badge>
                                        @endif
                                    </div>
                                    <flux:text class="truncate">{{ $user->email }}</flux:text>
                                </div>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-wrap gap-1">
                                @foreach ($user->ownedTeams as $team)
                                    <flux:badge size="sm" color="blue" inset="top bottom">{{ $team->name }} · owner</flux:badge>
                                @endforeach
                                @foreach ($user->teams as $team)
                                    <flux:badge size="sm" color="zinc" inset="top bottom">{{ $team->name }} · {{ $team->membership->role ?? 'member' }}</flux:badge>
                                @endforeach
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-wrap gap-1">
                                @if ($user->email_verified_at)
                                    <flux:badge size="sm" color="green" inset="top bottom">Verified</flux:badge>
                                @else
                                    <flux:badge size="sm" color="orange" inset="top bottom">Unverified</flux:badge>
                                @endif
                                @if ($user->two_factor_confirmed_at)
                                    <flux:badge size="sm" color="green" inset="top bottom">2FA</flux:badge>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $user->tokens_count }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($user->last_login_at)
                                <span title="{{ $user->last_login_at->toDayDateTimeString() }}">{{ $user->last_login_at->diffForHumans() }}</span>
                            @else
                                <flux:text>Never</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $user->created_at->toFormattedDateString() }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:dropdown position="bottom" align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" data-test="user-actions-{{ $user->id }}" />
                                <flux:menu>
                                    <flux:menu.item icon="key" wire:click="revokeTokens({{ $user->id }})" :disabled="$user->tokens_count === 0">
                                        Revoke MCP tokens
                                    </flux:menu.item>
                                    <flux:menu.separator />
                                    <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $user->id }})" :disabled="$user->id === auth()->id()">
                                        Delete user
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7">
                            <flux:text class="py-6 text-center">No users match that search.</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <flux:modal wire:model="deletingUserId" class="max-w-md">
            @if ($deletingUser)
                <div class="space-y-4">
                    <flux:heading size="lg">Delete {{ $deletingUser->name }}?</flux:heading>
                    <flux:text>
                        This removes their account, every API token, and their membership of
                        {{ $deletingUser->teams->count() }} {{ Str::plural('team', $deletingUser->teams->count()) }}.
                        @if ($deletingUser->ownedTeams->isNotEmpty())
                            It also deletes the {{ $deletingUser->ownedTeams->count() }} {{ Str::plural('team', $deletingUser->ownedTeams->count()) }}
                            they own ({{ $deletingUser->ownedTeams->pluck('name')->join(', ') }}) along with every epic, squad and engineer in them.
                        @endif
                        This cannot be undone.
                    </flux:text>
                    <div class="flex justify-end gap-3">
                        <flux:button variant="ghost" wire:click="$set('deletingUserId', null)">Cancel</flux:button>
                        <flux:button variant="danger" wire:click="delete" data-test="confirm-delete-user">Delete user</flux:button>
                    </div>
                </div>
            @endif
        </flux:modal>
    </x-admin.layout>
</div>
