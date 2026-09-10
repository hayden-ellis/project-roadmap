<!DOCTYPE html>
{{-- The theme class comes from the cookie the head script keeps, so the
     page is born in the right theme and wire:navigate has nothing to flip.
     A first visit has no cookie and renders light; the head script sets
     the class before anything paints. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => request()->cookie('appearance') === 'dark'])>

<head>
    @include('partials.head')
</head>

<body class="min-h-screen bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300">
    @php($navItems = \App\Support\Navigation::items())
    @php($avatar = auth()->user()->profile_photo_path ? auth()->user()->profile_photo_url : null)

    {{-- The header is the only navigation: no desktop sidebar, so the page
         gets the full width. No `container` on the header or on main either;
         Flux's container caps at max-w-7xl and centres what's left, which
         would strand the board in the middle of a wide screen. --}}
    <flux:header sticky class="z-30 border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.toggle class="mr-2 lg:hidden" icon="bars-2" inset="left" />

        <x-app-logo :href="route('now')" :responsive="true" wire:navigate />

        {{-- -mb-px drops the navbar a hair so a current item's underline
             lands on the header's own border rather than floating above it. --}}
        <flux:navbar class="-mb-px max-lg:hidden" data-test="primary-nav">
            @foreach ($navItems as $item)
                @if (isset($item['children']))
                    <flux:dropdown>
                        <flux:navbar.item
                            :icon="$item['icon']"
                            icon:trailing="chevron-down"
                            :current="$item['current']"
                            :data-test="'nav-'.$item['key'].'-link'"
                        >
                            {{ $item['label'] }}
                        </flux:navbar.item>

                        <flux:navmenu>
                            @foreach ($item['children'] as $child)
                                <flux:navmenu.item
                                    :icon="$child['icon']"
                                    :href="$child['href']"
                                    :current="$child['current']"
                                    wire:navigate
                                    :data-test="'nav-'.$child['key'].'-link'"
                                >
                                    {{ $child['label'] }}
                                </flux:navmenu.item>
                            @endforeach
                        </flux:navmenu>
                    </flux:dropdown>
                @else
                    <flux:navbar.item
                        :icon="$item['icon']"
                        :href="$item['href']"
                        :current="$item['current']"
                        wire:navigate
                        :data-test="'nav-'.$item['key'].'-link'"
                    >
                        {{ $item['label'] }}
                    </flux:navbar.item>
                @endif
            @endforeach
        </flux:navbar>

        <flux:spacer />

        {{-- flux:header is a bare `flex items-center` with no gap, so the
             right-hand cluster owns its own rhythm here. These stay in the
             header at every width; they never move into the drawer. --}}
        <div class="flex items-center gap-1">
            <x-theme-toggle />

            <livewire:notification-bell />
        </div>

        {{-- Direct child of the header, not of the cluster above: `my-*`
             sizes a vertical separator by trimming its parent's height, and
             only the header is the full 56px. --}}
        <flux:separator vertical class="mx-3 my-4" />

        <flux:dropdown position="bottom" align="end">
            <flux:profile
                circle
                :avatar="$avatar"
                :avatar:name="auth()->user()->name"
                :initials="auth()->user()->initials()"
                icon-trailing="chevron-down"
                data-test="user-menu-button"
            />

            <x-user-menu />
        </flux:dropdown>
    </flux:header>

    {{-- The header hides its links below `lg`; this is where they live at
         that size. There is no desktop sidebar in this layout. --}}
    <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 lg:hidden dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.header>
            <x-app-logo :sidebar="true" :href="route('now')" wire:navigate />
            <flux:sidebar.collapse />
        </flux:sidebar.header>

        <flux:sidebar.nav data-test="mobile-nav">
            @foreach ($navItems as $item)
                @if (isset($item['children']))
                    <flux:sidebar.group :heading="$item['label']" class="mt-2">
                        @foreach ($item['children'] as $child)
                            <flux:sidebar.item
                                :icon="$child['icon']"
                                :href="$child['href']"
                                :current="$child['current']"
                                wire:navigate
                                :data-test="'drawer-'.$child['key'].'-link'"
                            >
                                {{ $child['label'] }}
                            </flux:sidebar.item>
                        @endforeach
                    </flux:sidebar.group>
                @else
                    <flux:sidebar.item
                        :icon="$item['icon']"
                        :href="$item['href']"
                        :current="$item['current']"
                        wire:navigate
                        :data-test="'drawer-'.$item['key'].'-link'"
                    >
                        {{ $item['label'] }}
                    </flux:sidebar.item>
                @endif
            @endforeach
        </flux:sidebar.nav>
    </flux:sidebar>

    <flux:main class="pb-8">
        {{-- Jetstream's pages (profile, team settings, API tokens) hand the
             layout a heading; the Livewire pages carry their own. --}}
        @isset($header)
            <div class="mb-6">{{ $header }}</div>
        @endisset

        {{ $slot }}

        {{-- Minimal Footer --}}
        <footer class="mt-12 pt-6 border-t border-zinc-200 dark:border-zinc-700">
            <div class="flex items-center justify-between text-xs text-zinc-400">
                <span>Project Roadmap</span>
                <span>v1.0</span>
            </div>
        </footer>
    </flux:main>

    @persist('toast')
        <flux:toast.group>
            <flux:toast />
        </flux:toast.group>
    @endpersist

    @fluxScripts
</body>

</html>
