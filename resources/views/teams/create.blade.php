<x-app-layout>
    <x-slot name="title">{{ __('Create team') }}</x-slot>

    <x-settings.layout :heading="__('New team')" :subheading="__('A team has its own board, squads, epics and people')">
        @livewire('teams.create-team-form')
    </x-settings.layout>
</x-app-layout>
