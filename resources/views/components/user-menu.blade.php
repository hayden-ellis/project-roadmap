{{-- The menu body behind the profile chip. Team management used to have its
     own dropdown in the sidebar; with a single header there is one menu for
     "me", and the team lives in it. --}}
@php($user = auth()->user())
@php($avatar = $user->profile_photo_path ? $user->profile_photo_url : null)

<flux:menu class="w-[240px]">
    <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
        <flux:avatar :src="$avatar" :name="$user->name" :initials="$user->initials()" />
        <div class="grid flex-1 text-start text-sm leading-tight">
            <flux:heading class="truncate">{{ $user->name }}</flux:heading>
            <flux:text class="truncate">{{ $user->email }}</flux:text>
        </div>
    </div>

    <flux:menu.separator />

    <flux:menu.radio.group>
        <flux:menu.item :href="route('profile.show')" icon="cog-6-tooth" wire:navigate data-test="settings-link">
            {{ __('Settings') }}
        </flux:menu.item>
    </flux:menu.radio.group>

    @if (Laravel\Jetstream\Jetstream::hasTeamFeatures())
        {{-- No separators here: a menu group draws its own divider. --}}
        <flux:menu.group :heading="$user->currentTeam?->name ?? __('Team')">
            <flux:menu.item :href="route('teams.show', $user->currentTeam->id)" icon="users" wire:navigate>
                {{ __('Team settings') }}
            </flux:menu.item>

            @can('create', Laravel\Jetstream\Jetstream::newTeamModel())
                <flux:menu.item :href="route('teams.create')" icon="plus-circle" wire:navigate>
                    {{ __('Create new team') }}
                </flux:menu.item>
            @endcan
        </flux:menu.group>

        @if ($user->allTeams()->count() > 1)
            <flux:menu.group :heading="__('Switch team')">
                @foreach ($user->allTeams() as $team)
                    <form method="POST" action="{{ route('current-team.update') }}">
                        @method('PUT')
                        @csrf
                        <input type="hidden" name="team_id" value="{{ $team->id }}">

                        <flux:menu.item as="button" type="submit" icon="building-office-2" class="w-full cursor-pointer">
                            <span class="flex w-full items-center justify-between">
                                <span>{{ $team->name }}</span>
                                @if ($team->id === $user->currentTeam->id)
                                    <flux:icon.check variant="micro" class="size-4 text-accent-content" />
                                @endif
                            </span>
                        </flux:menu.item>
                    </form>
                @endforeach
            </flux:menu.group>
        @endif
    @endif

    <form method="POST" action="{{ route('logout') }}" class="w-full">
        @csrf
        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer" data-test="logout-button">
            {{ __('Log out') }}
        </flux:menu.item>
    </form>
</flux:menu>
