<?php

use App\Models\Engineer;
use App\Models\EngineerQuarterCapacity;
use App\Support\Quarter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('components.layouts.app.sidebar')] class extends Component
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|email|max:255')]
    public string $email = '';

    #[Validate('nullable|string|max:255')]
    public string $title = '';

    #[Validate('nullable|exists:squads,id')]
    public string $squad_id = '';

    #[Validate('nullable|exists:users,id')]
    public string $user_id = '';

    #[Validate('required|integer|min:0')]
    public int $default_weekly_points = 10;

    public string $quarter = '';

    #[Validate('nullable|integer|min:0')]
    public ?int $available_points = null;

    public function mount(): void
    {
        $this->quarter = Quarter::current()->key();
    }

    public function save(): void
    {
        $this->authorize('create', Engineer::class);
        $this->validate();

        $quarter = Quarter::parse($this->quarter);

        DB::transaction(function () use ($quarter) {
            $engineer = Engineer::create([
                'team_id' => Auth::user()->currentTeam->id,
                'squad_id' => $this->squad_id ?: null,
                'user_id' => $this->user_id ?: null,
                'name' => $this->name,
                'email' => $this->email ?: null,
                'title' => $this->title ?: null,
                'default_weekly_points' => $this->default_weekly_points,
                'is_active' => true,
            ]);

            if ($this->available_points !== null) {
                EngineerQuarterCapacity::create([
                    'engineer_id' => $engineer->id,
                    'year' => $quarter->year,
                    'quarter' => $quarter->quarter,
                    'available_points' => $this->available_points,
                ]);
            }
        });

        $this->redirect('/engineers', navigate: true);
    }

    public function with(): array
    {
        $team = Auth::user()->currentTeam;

        return [
            'squads' => $team->squads()->ordered()->get(),
            'teamUsers' => $team->allUsers(),
            'quarters' => Quarter::current()->through(6),
        ];
    }
};
?>

<div class="max-w-2xl">
    <div class="pt-8 pb-10">
        <h1>Add Engineer</h1>
        <flux:text class="mt-1">A roster record. Linking a login is optional — plan for people before they have one.</flux:text>
    </div>

    <form wire:submit="save" class="space-y-6">
        <flux:card class="space-y-6">
            <flux:input wire:model="name" label="Name" placeholder="Sarah Chen" required />

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <flux:input wire:model="email" type="email" label="Email" placeholder="sarah@example.com" />
                <flux:input wire:model="title" label="Title" placeholder="Senior Engineer" />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <flux:select wire:model="squad_id" label="Squad" placeholder="No squad">
                    @foreach($squads as $squad)
                    <flux:select.option value="{{ $squad->id }}">{{ $squad->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="user_id" label="Linked account" placeholder="Not linked">
                    @foreach($teamUsers as $teamUser)
                    <flux:select.option value="{{ $teamUser->id }}">{{ $teamUser->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </flux:card>

        <flux:card class="space-y-6">
            <div>
                <flux:heading size="lg">Capacity</flux:heading>
                <flux:text class="mt-1">
                    The quarter figure is the envelope — it spreads evenly across the quarter's weeks.
                    You can shorten individual weeks for time off after saving.
                </flux:text>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <flux:select wire:model="quarter" label="Quarter">
                    @foreach($quarters as $option)
                    <flux:select.option value="{{ $option->key() }}">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input type="number" min="0" wire:model="available_points"
                            label="Points this quarter" placeholder="150" />

                <flux:input type="number" min="0" wire:model="default_weekly_points"
                            label="Fallback weekly"
                            description="Used for quarters with no figure set." />
            </div>
        </flux:card>

        <div class="flex items-center gap-3">
            <flux:button type="submit" variant="primary">Add Engineer</flux:button>
            <flux:button href="/engineers" variant="ghost" wire:navigate>Cancel</flux:button>
        </div>
    </form>
</div>
