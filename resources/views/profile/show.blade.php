<x-app-layout>
    <x-slot name="title">{{ __('Profile settings') }}</x-slot>

    <x-settings.layout :heading="__('Profile')" :subheading="__('Your photo, name and email address')">
        @if (Laravel\Fortify\Features::canUpdateProfileInformation())
            @livewire('profile.update-profile-information-form')
        @endif

        @if (Laravel\Jetstream\Jetstream::hasAccountDeletionFeatures())
            @livewire('profile.delete-user-form')
        @endif
    </x-settings.layout>
</x-app-layout>
