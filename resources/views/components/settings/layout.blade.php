{{-- The settings shell: one heading, a side list of sections, and a
     reading-width column for the section itself. Every settings page
     (profile, security, appearance, team) renders inside it so they share
     one shape. Modelled on the official Livewire starter kit's layout. --}}
@props(['heading' => '', 'subheading' => ''])

@php($team = auth()->user()->currentTeam)

<div class="relative mb-6 w-full">
    <flux:heading size="xl" level="1">{{ __('Settings') }}</flux:heading>
    <flux:subheading size="lg" class="mb-6">{{ __('Manage your profile, account and team') }}</flux:subheading>
    <flux:separator variant="subtle" />
</div>

<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <flux:navlist aria-label="{{ __('Settings') }}">
            <flux:navlist.item :href="route('profile.show')" :current="request()->routeIs('profile.show')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
            <flux:navlist.item :href="route('settings.security')" :current="request()->routeIs('settings.security')" wire:navigate>{{ __('Security') }}</flux:navlist.item>
            <flux:navlist.item :href="route('settings.appearance')" :current="request()->routeIs('settings.appearance')" wire:navigate>{{ __('Appearance') }}</flux:navlist.item>
            @if (Laravel\Jetstream\Jetstream::hasTeamFeatures() && $team)
                <flux:navlist.item :href="route('teams.show', $team->id)" :current="request()->routeIs('teams.*')" wire:navigate>{{ __('Team') }}</flux:navlist.item>
            @endif
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading }}</flux:heading>
        <flux:subheading>{{ $subheading }}</flux:subheading>

        <div class="mt-5 w-full max-w-lg">
            {{ $slot }}
        </div>
    </div>
</div>
