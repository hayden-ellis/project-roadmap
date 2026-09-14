{{-- The admin shell: same shape as the settings shell, so the panel reads as
     part of the app rather than a bolt-on. A side list of sections and a
     full-width column for the section itself, since these are tables. --}}
@props(['heading' => '', 'subheading' => ''])

<div class="relative mb-6 w-full pt-8">
    <flux:heading size="xl" level="1">{{ __('Admin') }}</flux:heading>
    <flux:subheading size="lg" class="mb-6">{{ __('Every user and team on this install') }}</flux:subheading>
    <flux:separator variant="subtle" />
</div>

<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <flux:navlist aria-label="{{ __('Admin') }}" data-test="admin-nav">
            <flux:navlist.item :href="route('admin.index')" :current="request()->routeIs('admin.index')" icon="squares-2x2" wire:navigate>{{ __('Overview') }}</flux:navlist.item>
            <flux:navlist.item :href="route('admin.users')" :current="request()->routeIs('admin.users')" icon="user" wire:navigate>{{ __('Users') }}</flux:navlist.item>
            <flux:navlist.item :href="route('admin.teams')" :current="request()->routeIs('admin.teams*')" icon="building-office-2" wire:navigate>{{ __('Teams') }}</flux:navlist.item>
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="min-w-0 flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading }}</flux:heading>
        <flux:subheading>{{ $subheading }}</flux:subheading>

        <div class="mt-5 w-full">
            {{ $slot }}
        </div>
    </div>
</div>
