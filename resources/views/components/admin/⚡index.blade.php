<?php

use App\Models\Epic;
use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app.header')] class extends Component
{
    public function with(): array
    {
        return [
            'stats' => [
                ['label' => 'Users', 'value' => User::count(), 'href' => route('admin.users'), 'icon' => 'user'],
                ['label' => 'Teams', 'value' => Team::count(), 'href' => route('admin.teams'), 'icon' => 'building-office-2'],
                ['label' => 'Epics', 'value' => Epic::count(), 'href' => null, 'icon' => 'rectangle-stack'],
                ['label' => 'MCP tokens', 'value' => PersonalAccessToken::count(), 'href' => route('admin.users'), 'icon' => 'key'],
            ],
            'recentUsers' => User::latest()->take(5)->get(),
        ];
    }
};
?>

<div>
    <x-admin.layout :heading="__('Overview')" :subheading="__('What is on this install right now')">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($stats as $stat)
                <flux:card :href="$stat['href']" wire:navigate @class(['hover:shadow-md transition-shadow' => $stat['href']])>
                    <div class="flex items-center gap-3">
                        <flux:icon :name="$stat['icon']" class="size-5 text-zinc-400" />
                        <flux:text>{{ $stat['label'] }}</flux:text>
                    </div>
                    <flux:heading size="xl" class="mt-2 tabular-nums" data-test="stat-{{ Str::slug($stat['label']) }}">{{ $stat['value'] }}</flux:heading>
                </flux:card>
            @endforeach
        </div>

        <flux:heading class="mt-10">{{ __('Newest users') }}</flux:heading>
        <flux:table class="mt-3">
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Email</flux:table.column>
                <flux:table.column>Joined</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($recentUsers as $user)
                    <flux:table.row :key="$user->id">
                        <flux:table.cell variant="strong">{{ $user->name }}</flux:table.cell>
                        <flux:table.cell>{{ $user->email }}</flux:table.cell>
                        <flux:table.cell>{{ $user->created_at->diffForHumans() }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </x-admin.layout>
</div>
