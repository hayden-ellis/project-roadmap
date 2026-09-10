{{-- Wraps a button whose action needs a fresh password. Jetstream's
     ConfirmsPasswords trait does the checking; this only asks and then
     fires the wrapped `wire:then` once the trait says yes. --}}
@props(['title' => __('Confirm your password'), 'content' => __('For your security, confirm your password to continue.'), 'button' => __('Confirm')])

@php
    $confirmableId = md5($attributes->wire('then'));
@endphp

<span
    {{ $attributes->wire('then') }}
    x-data
    x-ref="span"
    x-on:click="$wire.startConfirmingPassword('{{ $confirmableId }}')"
    x-on:password-confirmed.window="setTimeout(() => $event.detail.id === '{{ $confirmableId }}' && $refs.span.dispatchEvent(new CustomEvent('then', { bubbles: false })), 250);"
>
    {{ $slot }}
</span>

@once
<flux:modal wire:model.self="confirmingPassword" class="max-w-lg">
    <form wire:submit="confirmPassword" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ $title }}</flux:heading>
            <flux:subheading>{{ $content }}</flux:subheading>
        </div>

        <flux:input wire:model="confirmablePassword" error:name="confirmable_password" :label="__('Password')" type="password" viewable autocomplete="current-password" autofocus />

        <div class="flex justify-end gap-2">
            <flux:button variant="filled" wire:click="stopConfirmingPassword" wire:loading.attr="disabled">{{ __('Cancel') }}</flux:button>
            <flux:button variant="primary" type="submit" wire:loading.attr="disabled" dusk="confirm-password-button">{{ $button }}</flux:button>
        </div>
    </form>
</flux:modal>
@endonce
