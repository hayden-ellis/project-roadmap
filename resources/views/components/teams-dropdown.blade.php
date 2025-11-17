@if (Laravel\Jetstream\Jetstream::hasTeamFeatures())
<div class="max-lg:hidden mb-1">
    <flux:dropdown position="bottom" align="start" class="w-full">
        <button type="button" class="w-full group flex items-center p-1 rounded-lg gap-2 hover:bg-zinc-800/5 dark:hover:bg-white/10 transition-colors in-data-flux-sidebar-collapsed-desktop:mx-0 in-data-flux-sidebar-collapsed-desktop:px-0 in-data-flux-sidebar-collapsed-desktop:py-2 in-data-flux-sidebar-collapsed-desktop:border-0 in-data-flux-sidebar-collapsed-desktop:bg-transparent in-data-flux-sidebar-collapsed-desktop:justify-center">
            <div class="flex items-center justify-center size-8 rounded-md bg-indigo-100 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400 shrink-0 in-data-flux-sidebar-collapsed-desktop:bg-transparent in-data-flux-sidebar-collapsed-desktop:rounded-lg in-data-flux-sidebar-collapsed-desktop:hover:bg-zinc-800/5 in-data-flux-sidebar-collapsed-desktop:dark:hover:bg-white/10">
                <flux:icon icon="building-office-2" variant="micro" class="size-5" />
            </div>
            <div class="flex-1 text-left min-w-0 in-data-flux-sidebar-collapsed-desktop:hidden">
                <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Team</div>
                <div class="text-sm font-semibold text-zinc-900 dark:text-white truncate">{{ Auth::user()->currentTeam?->name }}</div>
            </div>
            <div class="shrink-0 ms-auto size-8 flex justify-center items-center in-data-flux-sidebar-collapsed-desktop:hidden">
                <flux:icon icon="chevrons-up-down" class="size-4 text-zinc-400 dark:text-white/80 group-hover:text-zinc-900 dark:group-hover:text-white" />
            </div>
        </button>

        <flux:menu class="w-[220px]">
            <!-- Team Management -->
            <div class="block px-3 py-2 text-xs font-medium text-zinc-500 dark:text-zinc-400">
                {{ __('Manage Team') }}
            </div>

            <flux:menu.item href="{{ route('teams.show', Auth::user()->currentTeam->id) }}" icon="cog-6-tooth">Team Settings</flux:menu.item>

            @can('create', Laravel\Jetstream\Jetstream::newTeamModel())
            <flux:menu.item href="{{ route('teams.create') }}" icon="plus-circle">Create New Team</flux:menu.item>
            @endcan

            @if (Auth::user()->allTeams()->count() > 1)
            <flux:menu.separator />
            
            <div class="block px-3 py-2 text-xs font-medium text-zinc-500 dark:text-zinc-400">
                {{ __('Switch Teams') }}
            </div>

            @foreach (Auth::user()->allTeams() as $team)
            <form method="POST" action="{{ route('current-team.update') }}" x-data>
                @method('PUT')
                @csrf
                <input type="hidden" name="team_id" value="{{ $team->id }}">
                
                <flux:menu.item 
                    as="button" 
                    type="submit" 
                    icon="building-office-2"
                    class="w-full {{ $team->id === Auth::user()->currentTeam->id ? 'bg-zinc-100 dark:bg-zinc-800' : '' }}">
                    <div class="flex items-center justify-between w-full">
                        <span>{{ $team->name }}</span>
                        @if ($team->id === Auth::user()->currentTeam->id)
                        <flux:icon icon="check" variant="micro" class="size-4 text-indigo-600 dark:text-indigo-400" />
                        @endif
                    </div>
                </flux:menu.item>
            </form>
            @endforeach
            @endif
        </flux:menu>
    </flux:dropdown>
</div>
@endif