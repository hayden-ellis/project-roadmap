@php($canManage = Gate::check('addTeamMember', $team))

<div class="space-y-12">
    {{-- Jetstream announces a successful add with "saved"; that closes the
         invite sheet and confirms it. A failed add leaves the sheet open,
         since the sheet is client-side state and the errors render inside it. --}}
    <span
        x-data
        x-init="$wire.$on('saved', () => { $flux.modal('add-member').close(); $flux.toast({ text: @js(__('Invitation sent.')), variant: 'success' }) })"
        hidden
    ></span>

    <section class="space-y-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <flux:heading>{{ __('Members') }}</flux:heading>
                <flux:subheading>{{ __('Who is on this team, and what they can do') }}</flux:subheading>
            </div>

            @if ($canManage)
                <flux:modal.trigger name="add-member">
                    <flux:button variant="primary" icon="user-plus" data-test="add-member-button">
                        {{ __('Add member') }}
                    </flux:button>
                </flux:modal.trigger>
            @endif
        </div>

        @if ($team->users->isNotEmpty())
            <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                @foreach ($team->users->sortBy('name') as $user)
                    <div class="flex items-center gap-4 p-4 {{ ! $loop->last ? 'border-b border-zinc-200 dark:border-zinc-700' : '' }}" data-test="member-row" wire:key="member-{{ $user->id }}">
                        <flux:avatar circle :name="$user->name" :src="$user->profile_photo_path ? $user->profile_photo_url : null" />

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">
                                {{ $user->name }}
                                @if ($team->user_id === $user->id)
                                    <span class="text-zinc-400 dark:text-zinc-500">&middot; {{ __('owner') }}</span>
                                @endif
                            </p>
                            <flux:text size="sm" class="truncate">{{ $user->email }}</flux:text>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            @if (Laravel\Jetstream\Jetstream::hasRoles() && $user->membership?->role)
                                @php($roleName = Laravel\Jetstream\Jetstream::findRole($user->membership->role)?->name ?? $user->membership->role)

                                @if (Gate::check('updateTeamMember', $team))
                                    <flux:button variant="outline" size="sm" icon:trailing="chevron-down" wire:click="manageRole('{{ $user->id }}')" data-test="member-role-trigger">
                                        {{ $roleName }}
                                    </flux:button>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ $roleName }}</flux:badge>
                                @endif
                            @endif

                            @if ($this->user->id === $user->id)
                                <flux:tooltip :content="__('Leave team')">
                                    <flux:button variant="ghost" size="sm" icon="arrow-right-start-on-rectangle" wire:click="$toggle('confirmingLeavingTeam')" :aria-label="__('Leave team')" data-test="leave-team-button" />
                                </flux:tooltip>
                            @elseif (Gate::check('removeTeamMember', $team))
                                <flux:tooltip :content="__('Remove from team')">
                                    <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="confirmTeamMemberRemoval('{{ $user->id }}')" :aria-label="__('Remove from team')" data-test="member-remove-button" />
                                </flux:tooltip>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    @if ($team->teamInvitations->isNotEmpty() && $canManage)
        <section class="space-y-6">
            <div>
                <flux:heading>{{ __('Pending invitations') }}</flux:heading>
                <flux:subheading>{{ __('Sent by email and not accepted yet') }}</flux:subheading>
            </div>

            <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                @foreach ($team->teamInvitations as $invitation)
                    <div class="flex items-center gap-4 p-4 {{ ! $loop->last ? 'border-b border-zinc-200 dark:border-zinc-700' : '' }}" data-test="invitation-row" wire:key="invitation-{{ $invitation->id }}">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <flux:icon.envelope class="size-5 text-zinc-500 dark:text-zinc-400" />
                        </div>

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ $invitation->email }}</p>
                            @if ($invitation->role)
                                <flux:text size="sm">{{ Laravel\Jetstream\Jetstream::findRole($invitation->role)?->name ?? $invitation->role }}</flux:text>
                            @endif
                        </div>

                        @if (Gate::check('removeTeamMember', $team))
                            <flux:tooltip :content="__('Cancel invitation')">
                                <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="cancelTeamInvitation({{ $invitation->id }})" :aria-label="__('Cancel invitation')" data-test="invitation-cancel-button" />
                            </flux:tooltip>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Add / invite --}}
    @if ($canManage)
        <flux:modal name="add-member" class="max-w-lg">
            <form wire:submit="addTeamMember" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Add a team member') }}</flux:heading>
                    <flux:subheading>{{ __('They get an email invitation and join when they accept it.') }}</flux:subheading>
                </div>

                <flux:input wire:model="addTeamMemberForm.email" error:name="email" :label="__('Email address')" type="email" required data-test="invite-email" />

                @if (count($this->roles) > 0)
                    <flux:radio.group wire:model="addTeamMemberForm.role" :label="__('Role')" variant="cards" class="max-sm:flex-col" data-test="invite-role">
                        @foreach ($this->roles as $role)
                            <flux:radio :value="$role->key" :label="$role->name" :description="$role->description" />
                        @endforeach
                    </flux:radio.group>
                    <flux:error name="role" />
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit" data-test="invite-submit">{{ __('Send invitation') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    {{-- Change role --}}
    <flux:modal wire:model.self="currentlyManagingRole" class="max-w-lg">
        <form wire:submit="updateRole" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Change role') }}</flux:heading>
                <flux:subheading>{{ __('What this person can do on the team.') }}</flux:subheading>
            </div>

            <flux:radio.group wire:model="currentRole" variant="cards" class="max-sm:flex-col">
                @foreach ($this->roles as $role)
                    <flux:radio :value="$role->key" :label="$role->name" :description="$role->description" />
                @endforeach
            </flux:radio.group>

            <div class="flex justify-end gap-2">
                <flux:button variant="filled" wire:click="stopManagingRole" wire:loading.attr="disabled">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" type="submit" wire:loading.attr="disabled">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Leave --}}
    <flux:modal wire:model.self="confirmingLeavingTeam" class="max-w-lg">
        <form wire:submit="leaveTeam" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Leave :name?', ['name' => $team->name]) }}</flux:heading>
                <flux:subheading>{{ __('You will lose access to its board until someone adds you back.') }}</flux:subheading>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button variant="filled" wire:click="$toggle('confirmingLeavingTeam')" wire:loading.attr="disabled">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" type="submit" wire:loading.attr="disabled">{{ __('Leave team') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Remove --}}
    <flux:modal wire:model.self="confirmingTeamMemberRemoval" class="max-w-lg">
        <form wire:submit="removeTeamMember" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Remove this person?') }}</flux:heading>
                <flux:subheading>{{ __('They lose access to the team right away. You can add them back later.') }}</flux:subheading>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button variant="filled" wire:click="$toggle('confirmingTeamMemberRemoval')" wire:loading.attr="disabled">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" type="submit" wire:loading.attr="disabled" data-test="remove-member-confirm">{{ __('Remove') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
