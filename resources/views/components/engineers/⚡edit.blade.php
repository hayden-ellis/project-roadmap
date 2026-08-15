<?php

use App\Models\Allocation;
use App\Models\Engineer;
use App\Models\EngineerQuarterCapacity;
use App\Models\EngineerWeekCapacity;
use App\Services\CapacityService;
use App\Support\Quarter;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app.sidebar')] class extends Component
{
    use WithFileUploads;

    public Engineer $engineer;

    /**
     * A photo, applied the moment it is picked -- no save button involved.
     * Stored locally on the public disk, so the roster works offline and no
     * face ever leaves the machine.
     */
    public $avatarUpload = null;

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

    public bool $is_active = true;

    #[Url]
    public string $quarter = '';

    public ?int $available_points = null;

    /** Y-m-d => points, only for weeks that deviate from the even spread. */
    public array $weekOverrides = [];

    /** Y-m-d => note */
    public array $weekNotes = [];

    public bool $confirmingDeletion = false;

    public function mount(Engineer $engineer): void
    {
        $this->authorize('update', $engineer);

        $this->engineer = $engineer;
        $this->name = $engineer->name;
        $this->email = $engineer->email ?? '';
        $this->title = $engineer->title ?? '';
        $this->squad_id = (string) ($engineer->squad_id ?? '');
        $this->user_id = (string) ($engineer->user_id ?? '');
        $this->default_weekly_points = $engineer->default_weekly_points;
        $this->is_active = $engineer->is_active;
        $this->quarter = $this->quarter ?: Quarter::current()->key();

        $this->loadQuarter();
    }

    public function updatedQuarter(): void
    {
        $this->loadQuarter();
    }

    public function updatedAvatarUpload(): void
    {
        $this->authorize('update', $this->engineer);

        $this->validate(['avatarUpload' => 'image|max:2048'], [
            'avatarUpload.image' => 'That file is not an image.',
            'avatarUpload.max' => 'Keep it under 2 MB.',
        ]);

        $this->engineer->updateAvatar($this->avatarUpload);
        $this->avatarUpload = null;
    }

    public function removeAvatar(): void
    {
        $this->authorize('update', $this->engineer);

        $this->engineer->deleteAvatar();
    }

    private function loadQuarter(): void
    {
        $quarter = Quarter::parse($this->quarter);

        $this->available_points = EngineerQuarterCapacity::where('engineer_id', $this->engineer->id)
            ->forQuarter($quarter)
            ->value('available_points');

        $this->weekOverrides = [];
        $this->weekNotes = [];

        $weeks = collect(Auth::user()->currentTeam->weekCalendar()->weeksIn($quarter))
            ->map(fn ($w) => $w->toDateString());

        EngineerWeekCapacity::where('engineer_id', $this->engineer->id)
            ->whereIn('week_start', $weeks)
            ->get()
            ->each(function ($row) {
                $this->weekOverrides[$row->week_start->toDateString()] = $row->available_points;
                $this->weekNotes[$row->week_start->toDateString()] = $row->note ?? '';
            });
    }

    public function save(): void
    {
        $this->authorize('update', $this->engineer);
        $this->validate();

        $quarter = Quarter::parse($this->quarter);

        $this->engineer->update([
            'squad_id' => $this->squad_id ?: null,
            'user_id' => $this->user_id ?: null,
            'name' => $this->name,
            'email' => $this->email ?: null,
            'title' => $this->title ?: null,
            'default_weekly_points' => $this->default_weekly_points,
            'is_active' => $this->is_active,
        ]);

        if ($this->available_points !== null && $this->available_points !== '') {
            EngineerQuarterCapacity::updateOrCreate(
                ['engineer_id' => $this->engineer->id, 'year' => $quarter->year, 'quarter' => $quarter->quarter],
                ['available_points' => $this->available_points],
            );
        } else {
            EngineerQuarterCapacity::where('engineer_id', $this->engineer->id)->forQuarter($quarter)->delete();
        }

        $this->redirect('/engineers?quarter='.$this->quarter, navigate: true);
    }

    /** Writes a sparse override row; clearing it removes the row entirely. */
    public function setWeek(string $week): void
    {
        $this->authorize('update', $this->engineer);

        $value = $this->weekOverrides[$week] ?? null;

        if ($value === null || $value === '') {
            EngineerWeekCapacity::where('engineer_id', $this->engineer->id)
                ->whereDate('week_start', $week)
                ->delete();

            unset($this->weekOverrides[$week], $this->weekNotes[$week]);

            return;
        }

        EngineerWeekCapacity::updateOrCreate(
            ['engineer_id' => $this->engineer->id, 'week_start' => $week],
            ['available_points' => (int) $value, 'note' => $this->weekNotes[$week] ?? null],
        );
    }

    public function clearWeek(string $week): void
    {
        $this->weekOverrides[$week] = null;
        $this->setWeek($week);
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->engineer);
        $this->engineer->deleteAvatar();
        $this->engineer->delete();

        $this->redirect('/engineers', navigate: true);
    }

    public function with(): array
    {
        $team = Auth::user()->currentTeam;
        $capacity = CapacityService::for($team);
        $quarter = Quarter::parse($this->quarter);
        $weeks = $team->weekCalendar()->weeksIn($quarter);

        $allocations = Allocation::where('engineer_id', $this->engineer->id)
            ->betweenWeeks($weeks[0], end($weeks))
            ->with('epic')
            ->get()
            ->groupBy(fn ($a) => $a->week_start->toDateString());

        return [
            'squads' => $team->squads()->ordered()->get(),
            'teamUsers' => $team->allUsers(),
            'quarters' => Quarter::current()->previous()->through(8),
            'weeks' => collect($weeks)->map(fn ($w) => [
                'date' => $w,
                'key' => $w->toDateString(),
                'capacity' => $capacity->weeklyCapacity($this->engineer, $w),
                'epics' => ($allocations[$w->toDateString()] ?? collect())->pluck('epic')->filter(),
                'share' => $capacity->allocatedShare($this->engineer, $w),
                'isCurrent' => $w->toDateString() === $capacity->currentWeek()->toDateString(),
            ]),
            'effectiveCapacity' => $capacity->quarterCapacity($this->engineer, $quarter),
            'allocated' => $capacity->quarterAllocatedPoints($this->engineer, $quarter),
            'remaining' => $capacity->remainingPoints($this->engineer, $quarter),
        ];
    }
};
?>

<div class="max-w-4xl">
    <div class="flex items-start justify-between gap-4 pt-8 pb-8">
        <div class="flex items-center gap-4">
            {{-- The face is the control: click it to change it. --}}
            <label class="relative block cursor-pointer group/avatar" title="Change photo">
                <x-engineer-avatar :engineer="$engineer" size="lg" :tooltip="false" />
                <span class="absolute inset-0 grid place-items-center rounded-full bg-black/40
                             opacity-0 group-hover/avatar:opacity-100 transition-opacity">
                    <flux:icon.camera variant="mini" class="text-white" />
                </span>
                <input type="file" accept="image/png,image/jpeg,image/webp" wire:model="avatarUpload" class="sr-only" />
            </label>
            <div>
                <h1>{{ $engineer->name }}</h1>
                <flux:text class="mt-1">{{ $engineer->title ?? 'Engineer' }}{{ $engineer->squad ? ' · '.$engineer->squad->name : '' }}</flux:text>
                <div class="mt-1 flex items-center gap-3">
                    <span class="text-xs text-zinc-400" wire:loading wire:target="avatarUpload">Uploading…</span>
                    @if($engineer->avatar_path)
                    <button type="button" wire:click="removeAvatar" wire:loading.remove wire:target="avatarUpload"
                            class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 cursor-pointer">
                        Remove photo
                    </button>
                    @endif
                </div>
                <flux:error name="avatarUpload" />
            </div>
        </div>
        <flux:button href="/engineers" variant="ghost" wire:navigate>Back</flux:button>
    </div>

    {{-- Quarter summary --}}
    <div class="grid grid-cols-3 gap-3 mb-6">
        <flux:card class="py-3 px-4">
            <flux:text class="text-xs">Capacity</flux:text>
            <div class="text-xl font-semibold mt-0.5">{{ $effectiveCapacity }}</div>
        </flux:card>
        <flux:card class="py-3 px-4">
            <flux:text class="text-xs">Allocated</flux:text>
            <div class="text-xl font-semibold mt-0.5">{{ $allocated }}</div>
        </flux:card>
        <flux:card class="py-3 px-4">
            <flux:text class="text-xs">Remaining</flux:text>
            <div class="text-xl font-semibold mt-0.5 {{ $remaining < 0 ? 'text-red-600 dark:text-red-400' : '' }}">{{ $remaining }}</div>
        </flux:card>
    </div>

    <form wire:submit="save" class="space-y-6">
        <flux:card class="space-y-6">
            <flux:input wire:model="name" label="Name" required />

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <flux:input wire:model="email" type="email" label="Email" />
                <flux:input wire:model="title" label="Title" />
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

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <flux:input type="number" min="0" wire:model="default_weekly_points" label="Fallback weekly points" />
                <div class="flex items-end pb-2">
                    <flux:switch wire:model="is_active" label="Active"
                                 description="Inactive engineers are excluded from squad capacity." />
                </div>
            </div>
        </flux:card>

        <flux:card class="space-y-5">
            <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                <div>
                    <flux:heading size="lg">Capacity</flux:heading>
                    <flux:text class="mt-1">The quarter figure spreads evenly; override individual weeks for time off.</flux:text>
                </div>
                <flux:select wire:model.live="quarter" class="w-40">
                    @foreach($quarters as $option)
                    <flux:select.option value="{{ $option->key() }}">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:input type="number" min="0" wire:model="available_points"
                        label="Points this quarter"
                        description="Leave empty to fall back to the weekly default." />

            <div>
                <flux:text class="font-medium mb-2">Weeks</flux:text>
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach($weeks as $week)
                    @php $isOverride = array_key_exists($week['key'], $weekOverrides) && $weekOverrides[$week['key']] !== null; @endphp
                    <div class="flex flex-wrap items-center gap-3 px-3 py-2 {{ $week['isCurrent'] ? 'bg-blue-50 dark:bg-blue-950/30' : '' }}">
                        <div class="w-28 shrink-0">
                            <div class="text-sm font-medium">{{ $week['date']->format('M j') }}</div>
                            @if($week['isCurrent'])
                            <div class="text-[10px] text-blue-600 dark:text-blue-400 font-medium">THIS WEEK</div>
                            @endif
                        </div>

                        <div class="flex items-center gap-2 shrink-0">
                            <flux:input type="number" min="0" size="sm" class="w-20"
                                        wire:model="weekOverrides.{{ $week['key'] }}"
                                        wire:change="setWeek('{{ $week['key'] }}')"
                                        placeholder="{{ $week['capacity'] }}" />
                            <span class="text-xs text-zinc-500 dark:text-zinc-400">pts</span>
                        </div>

                        @if($isOverride)
                        <flux:input size="sm" class="w-40" placeholder="Reason"
                                    wire:model="weekNotes.{{ $week['key'] }}"
                                    wire:change="setWeek('{{ $week['key'] }}')" />
                        <flux:button type="button" size="sm" variant="ghost" icon="x-mark"
                                     wire:click="clearWeek('{{ $week['key'] }}')" title="Reset to the even spread" />
                        @endif

                        <div class="flex-1 flex flex-wrap items-center justify-end gap-1.5 min-w-0">
                            @foreach($week['epics'] as $epic)
                            <span class="text-[11px] px-1.5 py-0.5 rounded bg-zinc-100 dark:bg-zinc-800 truncate max-w-[160px]">
                                {{ $epic->title }}
                            </span>
                            @endforeach
                            @if($week['share'] > 1.0)
                            <flux:badge color="red" size="sm">{{ (int) round($week['share'] * 100) }}%</flux:badge>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </flux:card>

        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <flux:button type="submit" variant="primary">Save Changes</flux:button>
                <flux:button href="/engineers" variant="ghost" wire:navigate>Cancel</flux:button>
            </div>
            <flux:button type="button" variant="danger" wire:click="$set('confirmingDeletion', true)">Delete</flux:button>
        </div>
    </form>

    <flux:modal wire:model="confirmingDeletion" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">Delete {{ $engineer->name }}?</flux:heading>
            <flux:text>
                This removes their capacity and every allocation against them, which will change
                squad totals and may leave epics unstaffed. Consider marking them inactive instead.
            </flux:text>
            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="$set('confirmingDeletion', false)">Cancel</flux:button>
                <flux:button variant="danger" wire:click="delete">Delete Engineer</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
