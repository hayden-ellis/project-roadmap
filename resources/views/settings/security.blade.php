<x-app-layout>
    <x-slot name="title">{{ __('Security settings') }}</x-slot>

    <x-settings.layout :heading="__('Security')" :subheading="__('Your password, two-factor authentication and signed-in devices')">
        @if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::updatePasswords()))
            @livewire('profile.update-password-form')
        @endif

        @if (Laravel\Fortify\Features::canManageTwoFactorAuthentication())
            @livewire('profile.two-factor-authentication-form')
        @endif

        @livewire('profile.logout-other-browser-sessions-form')
    </x-settings.layout>
</x-app-layout>
