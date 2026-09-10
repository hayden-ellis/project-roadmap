<section class="mt-12 space-y-6">
    <div>
        <div class="flex items-center gap-2">
            <flux:heading>{{ __('Two-factor authentication') }}</flux:heading>
            @if ($this->enabled && ! $showingConfirmation)
                <flux:badge size="sm" color="green">{{ __('On') }}</flux:badge>
            @else
                <flux:badge size="sm" color="zinc">{{ __('Off') }}</flux:badge>
            @endif
        </div>
        <flux:subheading>{{ __('A code from your phone at sign-in, on top of your password') }}</flux:subheading>
    </div>

    <flux:text>
        @if ($this->enabled)
            @if ($showingConfirmation)
                {{ __('Scan the code with your authenticator app, then enter the six-digit code it shows to finish turning this on.') }}
            @else
                {{ __('You will be asked for a code from your authenticator app whenever you sign in.') }}
            @endif
        @else
            {{ __('When this is on, you will be asked for a code from an authenticator app such as Google Authenticator or 1Password whenever you sign in.') }}
        @endif
    </flux:text>

    @if ($this->enabled)
        @if ($showingQrCode)
            <div class="space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex flex-col items-center gap-4 sm:flex-row sm:items-start">
                    <div class="shrink-0 rounded-lg bg-white p-2">
                        {!! $this->user->twoFactorQrCodeSvg() !!}
                    </div>

                    <div class="min-w-0 space-y-2">
                        <flux:text size="sm">{{ __('Or enter this setup key by hand:') }}</flux:text>
                        <code class="block break-all rounded bg-zinc-100 px-2 py-1 font-mono text-xs dark:bg-zinc-800">{{ decrypt($this->user->two_factor_secret) }}</code>
                    </div>
                </div>

                @if ($showingConfirmation)
                    <flux:input
                        wire:model="code"
                        wire:keydown.enter="confirmTwoFactorAuthentication"
                        :label="__('Code from your app')"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        autofocus
                        class="max-w-xs"
                    />
                @endif
            </div>
        @endif

        @if ($showingRecoveryCodes)
            <div class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:text size="sm">
                    {{ __('Keep these recovery codes in a password manager. Each one signs you in once if you lose your phone.') }}
                </flux:text>

                <div class="grid gap-1 rounded-lg bg-zinc-100 p-4 font-mono text-sm dark:bg-zinc-800 dark:text-zinc-300" role="list" aria-label="{{ __('Recovery codes') }}">
                    @foreach (json_decode(decrypt($this->user->two_factor_recovery_codes), true) as $code)
                        <div role="listitem" class="select-text">{{ $code }}</div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif

    <div class="flex flex-wrap items-center gap-2">
        @if (! $this->enabled)
            <x-confirms-password wire:then="enableTwoFactorAuthentication">
                <flux:button variant="primary" type="button" wire:loading.attr="disabled">{{ __('Turn on') }}</flux:button>
            </x-confirms-password>
        @else
            @if ($showingRecoveryCodes)
                <x-confirms-password wire:then="regenerateRecoveryCodes">
                    <flux:button variant="filled" type="button" icon="arrow-path">{{ __('Regenerate codes') }}</flux:button>
                </x-confirms-password>
            @elseif ($showingConfirmation)
                <x-confirms-password wire:then="confirmTwoFactorAuthentication">
                    <flux:button variant="primary" type="button" wire:loading.attr="disabled">{{ __('Confirm') }}</flux:button>
                </x-confirms-password>
            @else
                <x-confirms-password wire:then="showRecoveryCodes">
                    <flux:button variant="filled" type="button" icon="eye">{{ __('Show recovery codes') }}</flux:button>
                </x-confirms-password>
            @endif

            @if ($showingConfirmation)
                <x-confirms-password wire:then="disableTwoFactorAuthentication">
                    <flux:button variant="ghost" type="button" wire:loading.attr="disabled">{{ __('Cancel') }}</flux:button>
                </x-confirms-password>
            @else
                <x-confirms-password wire:then="disableTwoFactorAuthentication">
                    <flux:button variant="danger" type="button" wire:loading.attr="disabled">{{ __('Turn off') }}</flux:button>
                </x-confirms-password>
            @endif
        @endif
    </div>
</section>
