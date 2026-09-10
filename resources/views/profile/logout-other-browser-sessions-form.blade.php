<section class="mt-12 space-y-6">
    <x-saved-toast on="loggedOut" text="Other sessions signed out." />

    <div>
        <flux:heading>{{ __('Signed-in devices') }}</flux:heading>
        <flux:subheading>{{ __('Where your account is signed in right now') }}</flux:subheading>
    </div>

    <flux:text>
        {{ __('Recent sessions are listed below; the list may not be complete. If you think your account has been compromised, sign the other devices out and change your password.') }}
    </flux:text>

    @php($sessions = collect($this->sessions))
    @php($shown = $sessions->take(5))

    @if ($sessions->isNotEmpty())
        {{-- Jetstream lists every session it can find, oldest last; five is
             enough to spot a stranger without the page turning into a log. --}}
        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
            @foreach ($shown as $session)
                <div class="flex items-center gap-4 p-4 {{ ! $loop->last ? 'border-b border-zinc-200 dark:border-zinc-700' : '' }}">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-zinc-100 dark:bg-zinc-800">
                        @if ($session->agent->isDesktop())
                            <flux:icon.computer-desktop class="size-5 text-zinc-500 dark:text-zinc-400" />
                        @else
                            <flux:icon.device-phone-mobile class="size-5 text-zinc-500 dark:text-zinc-400" />
                        @endif
                    </div>

                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium">
                            {{ $session->agent->platform() ?: __('Unknown') }} &middot; {{ $session->agent->browser() ?: __('Unknown') }}
                        </p>
                        <flux:text size="sm">
                            {{ $session->ip_address }},
                            @if ($session->is_current_device)
                                <span class="font-semibold text-green-600 dark:text-green-400">{{ __('this device') }}</span>
                            @else
                                {{ __('last active') }} {{ $session->last_active }}
                            @endif
                        </flux:text>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($sessions->count() > $shown->count())
            <flux:text size="sm">{{ __('And :count more.', ['count' => $sessions->count() - $shown->count()]) }}</flux:text>
        @endif
    @endif

    <div>
        <flux:button variant="filled" wire:click="confirmLogout" wire:loading.attr="disabled">
            {{ __('Sign out other devices') }}
        </flux:button>
    </div>

    <flux:modal wire:model.self="confirmingLogout" class="max-w-lg">
        <form wire:submit="logoutOtherBrowserSessions" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Sign out other devices') }}</flux:heading>
                <flux:subheading>{{ __('Enter your password to sign out of every other browser and device.') }}</flux:subheading>
            </div>

            <flux:input wire:model="password" :label="__('Password')" type="password" viewable autocomplete="current-password" autofocus />

            <div class="flex justify-end gap-2">
                <flux:button variant="filled" wire:click="$toggle('confirmingLogout')" wire:loading.attr="disabled">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" type="submit" wire:loading.attr="disabled">{{ __('Sign out other devices') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
