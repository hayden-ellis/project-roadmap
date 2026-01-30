<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">

<head>
    @include('partials.head')
</head>

<body class="min-h-screen bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300">
    <flux:sidebar sticky collapsible class="bg-zinc-50 dark:bg-zinc-900 border-r border-zinc-200 dark:border-zinc-700">
        <flux:sidebar.header>
            <flux:sidebar.brand
                href="/roadmap"
                logo="{{ asset('roadmap-icon.png') }}"
                logo:dark="{{ asset('roadmap-icon.png') }}"
                name="Project Roadmap" />
            <flux:sidebar.collapse class="in-data-flux-sidebar-on-desktop:not-in-data-flux-sidebar-collapsed-desktop:-mr-2" />
        </flux:sidebar.header>


        <flux:sidebar.nav>
            <flux:sidebar.item icon="map-pin" href="/roadmap" wire:navigate>Roadmap</flux:sidebar.item>
            <flux:sidebar.item icon="rectangle-stack" href="/epics" wire:navigate>Epics</flux:sidebar.item>
            <flux:sidebar.item icon="users" href="/squads" wire:navigate>Squads</flux:sidebar.item>
            <flux:sidebar.item icon="tag" href="/categories" wire:navigate>Categories</flux:sidebar.item>
            <flux:sidebar.item icon="clipboard-document-list" href="/planning" wire:navigate>Planning</flux:sidebar.item>
        </flux:sidebar.nav>

        <flux:sidebar.spacer />

        <flux:sidebar.nav>
            <flux:sidebar.item as="button" icon="moon" x-data x-on:click="$flux.dark = ! $flux.dark">
                {{ __('Dark Mode') }}
            </flux:sidebar.item>
        </flux:sidebar.nav>

        <!-- Teams Dropdown -->
        <x-teams-dropdown />

        <!-- Desktop User Menu -->
        <flux:dropdown position="bottom" align="start">
            <flux:sidebar.profile
                :name="auth()->user()->name"
                :initials="auth()->user()->initials()"
                icon-trailing="chevrons-up-down" />

            <flux:menu class="w-[220px]">
                <flux:menu.radio.group>
                    <div class="p-0 text-sm font-normal">
                        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                            <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                <span
                                    class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
                                    {{ auth()->user()->initials() }}
                                </span>
                            </span>

                            <div class="grid flex-1 text-left text-sm leading-tight">
                                <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                            </div>
                        </div>
                    </div>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <flux:menu.radio.group>
                    <flux:menu.item href="{{ route('profile.show') }}" icon="user" wire:navigate>{{ __('Profile') }}</flux:menu.item>
                
                </flux:menu.radio.group>

                <flux:menu.separator />

                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf
                    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                        {{ __('Log Out') }}
                    </flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
    </flux:sidebar>


    <flux:header class="lg:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
        <flux:spacer />
        <flux:dropdown position="top" alignt="start">
            <flux:profile avatar="{{ auth()->user()->profile_photo_url }}" :initials="auth()->user()->initials()" />
            <flux:menu>
                <flux:menu.radio.group>
                    <flux:menu.radio checked>{{ auth()->user()->name }}</flux:menu.radio>
                </flux:menu.radio.group>
                <flux:menu.separator />
                <flux:menu.item icon="arrow-right-start-on-rectangle">Logout</flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </flux:header>

    <flux:main class="pb-8">
        {{ $slot }}

        {{-- Minimal Footer --}}
        <footer class="mt-12 pt-6 border-t border-zinc-200 dark:border-zinc-700">
            <div class="flex items-center justify-between text-xs text-zinc-400">
                <span>Project Roadmap</span>
                <span>v1.0</span>
            </div>
        </footer>
    </flux:main>

    @fluxScripts
</body>

</html>