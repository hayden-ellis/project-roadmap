<x-app-layout>
    <x-slot name="title">{{ __('Appearance settings') }}</x-slot>

    <x-settings.layout :heading="__('Appearance')" :subheading="__('Light, dark, or whatever your device is using')">
        {{-- Binds straight to Flux's stored choice; the header toggle writes
             the same value, so the two never disagree. This is the only
             place "system" can be picked. --}}
        <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
            <flux:radio value="light" icon="sun">{{ __('Light') }}</flux:radio>
            <flux:radio value="dark" icon="moon">{{ __('Dark') }}</flux:radio>
            <flux:radio value="system" icon="computer-desktop">{{ __('System') }}</flux:radio>
        </flux:radio.group>
    </x-settings.layout>
</x-app-layout>
