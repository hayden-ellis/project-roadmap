<?php

use Illuminate\Support\Facades\Auth;
use Laravel\Jetstream\ConfirmsPasswords;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Personal tokens for the MCP server.
 *
 * A token acts as the person who minted it, on whichever team is current
 * for them, so it can never see or do more than they can in the browser.
 * The secret is shown once, straight after minting, alongside the command
 * that connects Claude Code; after that only the name, the access and the
 * dates remain. Minting asks for the password again, so a walked-away
 * session cannot quietly turn into a ninety-day credential.
 */
new #[Layout('components.layouts.app.header')] class extends Component
{
    use ConfirmsPasswords;

    #[Validate('required|string|max:60')]
    public string $name = 'claude-code';

    /** 'read' or 'write'. Write is only offered to people who can edit epics. */
    public string $access = 'read';

    /** The secret just minted, until the page is left or dismissed. */
    public ?string $plainTextToken = null;

    public ?string $mintedName = null;

    public bool $mintedWrite = false;

    public function create(): void
    {
        $this->ensurePasswordIsConfirmed();

        $this->validate();

        $user = Auth::user();

        if (! $user->currentTeam) {
            $this->addError('name', 'You need a current team before a token can see anything.');

            return;
        }

        $write = $this->access === 'write' && $this->canWrite();

        $token = $user->createToken(
            trim($this->name),
            $write ? ['mcp:read', 'mcp:write'] : ['mcp:read'],
            now()->addMinutes((int) config('sanctum.expiration')),
        );

        $this->plainTextToken = $token->plainTextToken;
        $this->mintedName = trim($this->name);
        $this->mintedWrite = $write;

        $this->name = 'claude-code';
        $this->access = 'read';
    }

    public function dismiss(): void
    {
        $this->plainTextToken = null;
        $this->mintedName = null;
        $this->mintedWrite = false;
    }

    public function revoke(int $tokenId): void
    {
        $token = Auth::user()->tokens()->findOr($tokenId, fn () => abort(404));
        $token->delete();

        Flux::toast("Revoked {$token->name}.");
    }

    /** Write tokens need the same standing as editing an epic in the browser. */
    public function canWrite(): bool
    {
        $user = Auth::user();

        return $user->currentTeam !== null && $user->hasTeamPermission($user->currentTeam, 'update');
    }

    public function with(): array
    {
        $user = Auth::user();

        return [
            'tokens' => $user->tokens()->latest()->latest('id')->get(),
            'team' => $user->currentTeam,
            'canWrite' => $this->canWrite(),
            'expiresInDays' => (int) round((int) config('sanctum.expiration') / 1440),
            'mcpUrl' => url('/mcp'),
            'connectCommand' => $this->plainTextToken
                ? sprintf('claude mcp add --transport http roadmap %s --header "Authorization: Bearer %s"', url('/mcp'), $this->plainTextToken)
                : null,
        ];
    }
};
?>

<div>
    <x-settings.layout :heading="__('Claude Code')" :subheading="__('Connect Claude Code to the roadmap with a personal token')">
        <div class="space-y-8">
            {{-- The secret, once. It lives in this component's state and
                 nowhere else, so leaving the page is the same as forgetting it. --}}
            @if ($plainTextToken)
            <div class="rounded-xl border border-emerald-200 dark:border-emerald-900/60 bg-emerald-50/60 dark:bg-emerald-950/20 p-4 space-y-4" data-test="new-token">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <flux:heading size="sm">Your new token</flux:heading>
                        <flux:text class="mt-0.5 text-sm">
                            <span class="font-medium">{{ $mintedName }}</span> · {{ $mintedWrite ? 'read and write' : 'read only' }}.
                            Copy it now — it is not shown again.
                        </flux:text>
                    </div>
                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="dismiss" aria-label="Dismiss" />
                </div>

                <flux:input readonly copyable :value="$plainTextToken" class="font-mono text-xs" label="Token" />

                <flux:input readonly copyable :value="$connectCommand" class="font-mono text-xs" label="Connect Claude Code"
                            description="Run this once in your terminal. Claude Code keeps the token in its own config." />
            </div>
            @endif

            {{-- Minting. Not a form: the password prompt inside brings its
                 own, and a form within a form is dropped by the browser. --}}
            <div class="space-y-4">
                <div>
                    <flux:heading size="sm">New token</flux:heading>
                    <flux:text class="mt-0.5 text-sm">
                        A token acts as you on <span class="font-medium">{{ $team?->name ?? 'your current team' }}</span>: it sees what you see.
                        Tokens expire after {{ $expiresInDays }} days.
                    </flux:text>
                </div>

                <flux:input wire:model="name" label="Name" placeholder="claude-code" description="Where it will be used — a laptop, a machine, a project." />

                <flux:radio.group wire:model="access" label="Access" variant="cards" class="max-sm:flex-col">
                    <flux:radio value="read" label="Read only" description="List epics, read comments and history." />
                    <flux:radio value="write" label="Read and write" :disabled="! $canWrite"
                                :description="$canWrite ? 'Also post comments, edit epics and move them between statuses.' : 'Needs an editor or admin role on this team.'" />
                </flux:radio.group>

                <div class="flex items-center gap-3">
                    <x-confirms-password wire:then="create" :content="__('Confirm your password to mint a token that acts as you.')">
                        <flux:button variant="primary" icon="key" data-test="mint-token">Mint token</flux:button>
                    </x-confirms-password>
                    <flux:error name="name" />
                </div>
            </div>

            {{-- What is out there --}}
            <div class="space-y-3">
                <div>
                    <flux:heading size="sm">Active tokens</flux:heading>
                    <flux:text class="mt-0.5 text-sm">Revoke anything you do not recognise, or a laptop you no longer have.</flux:text>
                </div>

                @forelse ($tokens as $token)
                <div class="flex items-center gap-3 rounded-lg border border-zinc-200 dark:border-zinc-700 px-4 py-3" wire:key="token-{{ $token->id }}" data-test="token-{{ $token->id }}">
                    <flux:icon.key variant="mini" class="size-4 shrink-0 text-zinc-400" />
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="truncate text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $token->name }}</span>
                            @if ($token->can('mcp:write'))
                            <flux:badge size="sm" color="amber" inset="top bottom">read + write</flux:badge>
                            @else
                            <flux:badge size="sm" color="zinc" inset="top bottom">read only</flux:badge>
                            @endif
                            @if ($token->expires_at?->isPast())
                            <flux:badge size="sm" color="red" inset="top bottom">expired</flux:badge>
                            @endif
                        </div>
                        <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                            Created {{ $token->created_at->diffForHumans() }}
                            · {{ $token->last_used_at ? 'last used '.$token->last_used_at->diffForHumans() : 'never used' }}
                            @if ($token->expires_at)
                            · {{ $token->expires_at->isPast() ? 'expired' : 'expires' }} {{ $token->expires_at->toFormattedDateString() }}
                            @endif
                        </div>
                    </div>
                    <flux:button size="xs" variant="subtle" class="text-red-600 dark:text-red-400" wire:click="revoke({{ $token->id }})"
                                 wire:confirm="Revoke {{ $token->name }}? Anything using it stops working now.">Revoke</flux:button>
                </div>
                @empty
                <flux:text class="text-sm">No tokens yet.</flux:text>
                @endforelse
            </div>
        </div>
    </x-settings.layout>
</div>
