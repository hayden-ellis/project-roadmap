<x-app-layout>
    <x-slot name="title">{{ __('Team settings') }}</x-slot>

    <x-settings.layout :heading="$team->name" :subheading="__('The team\'s name, who is on it, and their roles')">
        <div class="space-y-12">
            @livewire('teams.update-team-name-form', ['team' => $team])

            @livewire('teams.team-member-manager', ['team' => $team])

            @if (Gate::check('delete', $team) && ! $team->personal_team)
                @livewire('teams.delete-team-form', ['team' => $team])
            @endif
        </div>
    </x-settings.layout>
</x-app-layout>
