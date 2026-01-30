<?php

use Carbon\CarbonPeriod;
use Flux\DateRange;
use Livewire\Attributes\Async;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Component;

new #[Layout('components.layouts.app.sidebar')] class extends Component
{
    public DateRange $date_range;

    public array $selected_squads = [];

    public string $view_mode = 'months'; // weeks or months

    public function mount(): void
    {
        $this->date_range = DateRange::thisQuarter();
    }

    #[Async]
    #[Renderless]
    public function updateSquadWork(int $epicId, int $squadId, ?string $startDate, ?string $endDate): void
    {
        $epic = \App\Models\Epic::findOrFail($epicId);
        $this->authorize('update', $epic);

        $epic->squads()->updateExistingPivot($squadId, [
            'start_date' => $startDate ?: null,
            'end_date' => $endDate ?: null,
        ]);
    }

    public function with(): array
    {
        $query = auth()->user()->currentTeam
            ->epics()
            ->with(['status', 'squads', 'stories.status', 'stories.squad'])
            ->whereNotNull('start_date')
            ->whereNotNull('end_date');

        // Filter epics that overlap with the selected date range
        $query->where('end_date', '>=', $this->date_range->start())
            ->where('start_date', '<=', $this->date_range->end());

        if (! empty($this->selected_squads)) {
            $query->whereHas('squads', function ($q) {
                $q->whereIn('squads.id', $this->selected_squads);
            });
        }

        $epics = $query->orderBy('start_date')->get();
        $squads = auth()->user()->currentTeam->squads()->orderBy('name')->get();

        // Generate calendar periods
        if ($this->view_mode === 'weeks') {
            $startDate = $this->date_range->start()->startOfWeek();
            $endDate = $this->date_range->end()->endOfWeek();
            $period = CarbonPeriod::create($startDate, '1 day', $endDate);

            $weeks = [];
            $currentWeek = [];
            $weekNumber = 0;

            foreach ($period as $date) {
                $currentWeek[] = $date->copy();

                if ($date->isSunday() || $date->equalTo($endDate)) {
                    $weeks[$weekNumber] = $currentWeek;
                    $currentWeek = [];
                    $weekNumber++;
                }
            }

            return [
                'epics' => $epics,
                'squads' => $squads,
                'weeks' => $weeks,
                'months' => [],
                'startDate' => $startDate,
                'endDate' => $endDate,
            ];
        } else {
            // Monthly view
            $startDate = $this->date_range->start()->startOfMonth();
            $endDate = $this->date_range->end()->endOfMonth();

            $months = [];
            $currentMonth = $startDate->copy();

            while ($currentMonth <= $endDate) {
                $months[] = [
                    'start' => $currentMonth->copy()->startOfMonth(),
                    'end' => $currentMonth->copy()->endOfMonth(),
                    'date' => $currentMonth->copy(),
                ];
                $currentMonth->addMonth();
            }

            return [
                'epics' => $epics,
                'squads' => $squads,
                'weeks' => [],
                'months' => $months,
                'startDate' => $startDate,
                'endDate' => $endDate,
            ];
        }
    }
};
?>

<div class="-m-3 lg:-m-8 p-1 lg:p-8" x-data="{ 
    sidebarCollapsed: false,
    init() {
        const sidebar = document.querySelector('[data-flux-sidebar]');
        this.sidebarCollapsed = sidebar?.hasAttribute('data-flux-sidebar-collapsed-desktop') ?? false;
        
        const observer = new MutationObserver(() => {
            this.sidebarCollapsed = sidebar?.hasAttribute('data-flux-sidebar-collapsed-desktop') ?? false;
        });
        
        if (sidebar) {
            observer.observe(sidebar, { attributes: true, attributeFilter: ['data-flux-sidebar-collapsed-desktop'] });
        }
    }
}">
    <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4 md:gap-0 pb-10">
        <div class="flex flex-col md:flex-row items-start md:items-center gap-4">
            <h1>Roadmap Calendar</h1>
            <x-roadmap-navigation currentView="calendar" />
        </div>
        <flux:tabs variant="segmented" wire:model.live="view_mode">
            <flux:tab name="months" icon="calendar-days">Monthly</flux:tab>
            <flux:tab name="weeks" icon="calendar">Weekly</flux:tab>
        </flux:tabs>
    </div>

    <flux:card class="mb-6">
        <div class="flex flex-col md:flex-row items-start md:items-center gap-4 md:gap-12">
            <flux:date-picker 
                wire:model.live="date_range"
                mode="range" 
                with-presets
                presets="thisQuarter lastQuarter thisMonth lastMonth last30Days last3Months thisYear yearToDate"
                >
                <x-slot name="trigger">
                    <div class="flex flex-col sm:flex-row gap-4">
                        <flux:date-picker.input class="mt-2" label="Start" />
                        <flux:date-picker.input class="mt-2" label="End" />
                    </div>
                </x-slot>
            </flux:date-picker>

            <flux:field>
                <flux:label>Filter by Squads</flux:label>
                <div class="flex flex-wrap gap-2 mt-2">
                    @foreach($squads as $squad)
                        <label class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border cursor-pointer transition-colors {{ in_array($squad->id, $selected_squads) ? 'border-zinc-400 dark:border-zinc-500 bg-zinc-100 dark:bg-zinc-800' : 'border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800' }}">
                            <input type="checkbox" wire:model.live="selected_squads" value="{{ $squad->id }}" class="rounded border-zinc-300 dark:border-zinc-700" />
                            <div class="h-3 w-3 rounded-full" style="background-color: {{ $squad->color }}"></div>
                            <span class="text-sm">{{ $squad->name }}</span>
                        </label>
                    @endforeach
                </div>
            </flux:field>
        </div>
    </flux:card>

    @if($epics->isEmpty())
        <flux:card>
            <div class="text-center py-12">
                <flux:icon.calendar class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="lg" class="mt-4">No epics in this date range</flux:heading>
                <flux:text class="mt-2">Adjust your filters or create a new epic.</flux:text>
                <flux:button href="/epics/create" variant="primary" class="mt-6" wire:navigate>Create Epic</flux:button>
            </div>
        </flux:card>
    @else
        <div x-cloak class="overflow-x-auto">
            <div class="min-w-max">
            
            @if($view_mode === 'weeks')
                <!-- Month Headers -->
                <div class="flex border-b-2 border-zinc-300 dark:border-zinc-600">
                    <div class="flex-shrink-0 border-r border-zinc-200 dark:border-zinc-700" :class="sidebarCollapsed ? 'w-48 lg:w-72' : 'w-48'" x-cloak></div>
                    <div class="flex-1 flex">
                        @php
                            $monthData = [];
                            $currentMonth = null;
                            $currentMonthStart = null;
                            $monthSpan = 0;
                            
                            foreach($weeks as $weekIndex => $week) {
                                $weekMonth = $week[0]->format('Y-m');
                                
                                if ($currentMonth !== $weekMonth) {
                                    // Save the previous month if exists
                                    if ($currentMonth !== null && $currentMonthStart !== null) {
                                        $monthData[] = [
                                            'date' => $currentMonthStart,
                                            'span' => $monthSpan
                                        ];
                                    }
                                    
                                    // Start new month
                                    $currentMonth = $weekMonth;
                                    $currentMonthStart = $week[0];
                                    $monthSpan = 1;
                                } else {
                                    $monthSpan++;
                                }
                            }
                            
                            // Add the last month
                            if ($currentMonth !== null && $currentMonthStart !== null) {
                                $monthData[] = [
                                    'date' => $currentMonthStart,
                                    'span' => $monthSpan
                                ];
                            }
                        @endphp
                        
                        @foreach($monthData as $month)
                            <div class="border-r border-zinc-200 dark:border-zinc-700 bg-zinc-100 dark:bg-zinc-800" style="flex: {{ $month['span'] }}">
                                <div class="p-3 text-center font-bold text-base">
                                    {{ $month['date']->format('F Y') }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Week Headers -->
                <div class="flex border-b border-zinc-200 dark:border-zinc-700 relative overflow-visible">
                    <div class="flex-shrink-0 p-3 font-semibold border-r border-zinc-200 dark:border-zinc-700 sticky left-0 z-10 bg-white dark:bg-zinc-950 shadow-[2px_0_4px_rgba(0,0,0,0.05)]" :class="sidebarCollapsed ? 'w-48 lg:w-72' : 'w-48'" x-cloak>
                        Epic
                    </div>
                    <div class="flex-1 flex relative overflow-visible">
                        @foreach($weeks as $weekIndex => $week)
                            <div class="flex-1 min-w-32 border-r border-zinc-200 dark:border-zinc-700 relative overflow-visible">
                                <div class="p-2 text-center text-sm font-medium text-zinc-600 dark:text-zinc-400">
                                    Week {{ $weekIndex + 1 }}
                                </div>
                                <div class="flex text-xs text-center border-t border-zinc-200 dark:border-zinc-700">
                                    @foreach($week as $day)
                                        <div class="flex-1 p-1 {{ $day->isWeekend() ? 'bg-zinc-50 dark:bg-zinc-800' : '' }}">
                                            <div class="font-medium">{{ $day->format('D') }}</div>
                                            <div class="text-zinc-500 dark:text-zinc-400">{{ $day->format('j') }}</div>
                                        </div>
                                    @endforeach
                                </div>
                                @php
                                    $today = \Carbon\Carbon::today();
                                    $weekStart = $week[0];
                                    $weekEnd = end($week);
                                    $isTodayInWeek = $today >= $weekStart && $today <= $weekEnd;
                                @endphp
                                @if($isTodayInWeek)
                                    @php
                                        $daysFromStart = $weekStart->diffInDays($today);
                                        $todayPercent = (($daysFromStart / 7) * 100) + (100 / 7 / 2); // Center of the day
                                    @endphp
                                    <div class="absolute top-0 bottom-0 w-px bg-cyan-500/60 z-0 pointer-events-none overflow-visible" style="left: {{ $todayPercent }}%">
                                        <div class="absolute -top-6 left-1/2 -translate-x-1/2 text-[10px] font-bold text-cyan-600 dark:text-cyan-400 whitespace-nowrap bg-white dark:bg-zinc-950 px-1 rounded z-40">
                                            TODAY
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Epic Rows with Squad Breakdown -->
        @foreach($epics as $epic)
            @php
                // Use pivot data for each squad's dates and story points
                $squadWork = [];
                foreach($epic->squads as $squad) {
                    // If squads are filtered, only include selected squads
                    if (!empty($this->selected_squads) && !in_array($squad->id, $this->selected_squads)) {
                        continue;
                    }
                    
                    $squadStartDate = $squad->pivot->start_date ? \Carbon\Carbon::parse($squad->pivot->start_date) : null;
                    $squadEndDate = $squad->pivot->end_date ? \Carbon\Carbon::parse($squad->pivot->end_date) : null;
                    
                    // Only include squad work that overlaps with the selected date range
                    if ($squadStartDate && $squadEndDate) {
                        $overlapsWithRange = $squadStartDate <= $this->date_range->end() && $squadEndDate >= $this->date_range->start();
                        if (!$overlapsWithRange) {
                            continue;
                        }
                    }
                    
                    $storyCount = $epic->stories()->where('squad_id', $squad->id)->count();
                    $squadWork[$squad->id] = [
                        'squad' => $squad,
                        'start_date' => $squadStartDate,
                        'end_date' => $squadEndDate,
                        'story_count' => $storyCount,
                    ];
                }

                // Skip epic if no squad work overlaps with selected date range
                if (empty($squadWork)) {
                    continue;
                }
            @endphp

                    <!-- Compact Epic Header Row -->
                    <div class="flex border-b-2 border-zinc-300 dark:border-zinc-600 bg-zinc-50 dark:bg-zinc-900">
                        <div class="flex-shrink-0 py-1.5 px-3 border-r border-zinc-200 dark:border-zinc-700 sticky left-0 z-10 bg-zinc-50 dark:bg-zinc-900 shadow-[2px_0_4px_rgba(0,0,0,0.05)]" :class="sidebarCollapsed ? 'w-48 lg:w-72' : 'w-48'" x-cloak>
                            <flux:tooltip :content="$epic->title" class="w-full">
                                <a href="/epics/{{ $epic->id }}/edit" wire:navigate class="block hover:text-blue-600 dark:hover:text-blue-400">
                                    <div class="text-sm font-bold truncate text-zinc-900 dark:text-zinc-100">{{ $epic->title }}</div>
                                </a>
                            </flux:tooltip>
                        </div>
                        <div class="flex-1 flex relative">
                            <!-- Empty space for epic header -->
                        </div>
                    </div>

                    <!-- Squad Rows -->
                    @foreach($squadWork as $work)
                        <div class="flex border-b border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors">
                    <div class="flex-shrink-0 p-1.5 border-r border-zinc-200 dark:border-zinc-700 pl-8 sticky left-0 z-10 bg-white dark:bg-zinc-950 hover:bg-zinc-50 dark:hover:bg-zinc-800 shadow-[2px_0_4px_rgba(0,0,0,0.05)]" :class="sidebarCollapsed ? 'w-48 lg:w-72' : 'w-48'" x-cloak>
                        <div class="flex items-center gap-2">
                            <div class="h-2 w-2 rounded-full flex-shrink-0" style="background-color: {{ $work['squad']->color }}"></div>
                            <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ $work['squad']->name }}</span>
                        </div>
                        @if($work['start_date'] && $work['end_date'])
                        <div class="flex items-center gap-2 flex-nowrap whitespace-nowrap mt-1">
                            <div class="text-[10px] text-zinc-500 dark:text-zinc-400">
                                {{ $work['start_date']->format('M j') }} - {{ $work['end_date']->format('M j') }}
                            </div>
                        </div>
                        @endif
                    </div>
                            <div class="flex-1 flex relative" style="min-height: 50px;">
                                @if($work['start_date'] && $work['end_date'])
                                    @php
                                        $labelShown = false;
                                        $firstVisibleWeek = null;
                                    @endphp
                                    @foreach($weeks as $weekIndex => $week)
                                        <div class="flex-1 min-w-32 border-r border-zinc-200 dark:border-zinc-700 relative overflow-visible">
                                            @php
                                                $today = \Carbon\Carbon::today();
                                                $weekStart = $week[0];
                                                $weekEnd = end($week);
                                                $isTodayInWeek = $today >= $weekStart && $today <= $weekEnd;
                                                $squadStart = $work['start_date'];
                                                $squadEnd = $work['end_date'];
                                                
                                                // Check if squad's work overlaps with this week AND the selected date range
                                                $overlapsWithSelectedRange = $squadStart <= $this->date_range->end() && $squadEnd >= $this->date_range->start();
                                                $overlaps = ($squadStart <= $weekEnd && $squadEnd >= $weekStart) && $overlapsWithSelectedRange;
                                                
                                                if ($overlaps) {
                                                    // Track the first visible week for rounded corners
                                                    if ($firstVisibleWeek === null) {
                                                        $firstVisibleWeek = $weekIndex;
                                                    }
                                                    
                                                    // Calculate position and width within the week
                                                    $displayStart = max($squadStart, $weekStart);
                                                    $displayEnd = min($squadEnd, $weekEnd);
                                                    
                                                    // Calculate days from week start
                                                    $daysFromStart = $weekStart->diffInDays($displayStart);
                                                    $duration = $displayStart->diffInDays($displayEnd) + 1;
                                                    
                                                    // Convert to percentage
                                                    $leftPercent = ($daysFromStart / 7) * 100;
                                                    $widthPercent = ($duration / 7) * 100;
                                                    
                                                    // Show label on first segment with enough space (at least 30% width)
                                                    $hasEnoughSpace = $widthPercent >= 30;
                                                    $shouldShowLabel = !$labelShown && $hasEnoughSpace;
                                                    if ($shouldShowLabel) {
                                                        $labelShown = true;
                                                    }
                                                    
                                                    $isFirstVisibleWeek = $weekIndex === $firstVisibleWeek;
                                                    // Check if this is the last week
                                                    $isLastWeek = $squadEnd <= $weekEnd;
                                                }
                                            @endphp
                                            
                                            @if($isTodayInWeek)
                                                @php
                                                    $daysFromStart = $weekStart->diffInDays($today);
                                                    $todayPercent = (($daysFromStart / 7) * 100) + (100 / 7 / 2);
                                                @endphp
                                                <div class="absolute top-0 bottom-0 w-px bg-cyan-500/60 z-0 pointer-events-none" style="left: {{ $todayPercent }}%"></div>
                                            @endif
                                            
                                            @if($overlaps)
                                                <flux:dropdown position="top" align="start">
                                                    <button type="button" class="absolute top-2 bottom-2 rounded-lg flex items-center gap-2 px-2 text-white text-xs font-medium shadow-sm hover:shadow-md transition-shadow cursor-pointer"
                                                        style="left: {{ $leftPercent }}%; width: {{ $widthPercent }}%; background-color: {{ $work['squad']->color }}; {{ !$isFirstVisibleWeek ? 'border-top-left-radius: 0; border-bottom-left-radius: 0;' : '' }} {{ !$isLastWeek ? 'border-top-right-radius: 0; border-bottom-right-radius: 0;' : '' }}">
                                                    </button>
                                                    <x-roadmap.epic-squad-popover :epic="$epic" :work="$work" :squadStart="$squadStart" :squadEnd="$squadEnd" />
                                                </flux:dropdown>
                                            @endif
                                        </div>
                                    @endforeach
                                @endif
                            </div>
                        </div>
                    @endforeach
                @endforeach
            
            @else
                {{-- Monthly View --}}
                <!-- Month Headers -->
                <div class="flex border-b-2 border-zinc-300 dark:border-zinc-600 relative overflow-visible">
                    <div class="flex-shrink-0 p-3 font-semibold border-r border-zinc-200 dark:border-zinc-700 sticky left-0 z-10 bg-white dark:bg-zinc-950 shadow-[2px_0_4px_rgba(0,0,0,0.05)]" :class="sidebarCollapsed ? 'w-48 lg:w-72' : 'w-48'" x-cloak>
                        Epic
                    </div>
                    <div class="flex-1 flex relative overflow-visible">
                        @foreach($months as $monthIndex => $month)
                            <div class="flex-1 min-w-40 border-r border-zinc-200 dark:border-zinc-700 bg-zinc-100 dark:bg-zinc-800 relative overflow-visible">
                                <div class="p-3 text-center font-bold text-base relative">
                                    {{ $month['date']->format('M Y') }}
                                    @php
                                        $today = \Carbon\Carbon::today();
                                        $monthStart = $month['start'];
                                        $monthEnd = $month['end'];
                                        $isTodayInMonth = $today >= $monthStart && $today <= $monthEnd;
                                    @endphp
                                    @if($isTodayInMonth)
                                        @php
                                            $daysInMonth = $monthStart->daysInMonth;
                                            $daysFromStart = $monthStart->diffInDays($today);
                                            $todayPercent = (($daysFromStart / $daysInMonth) * 100) + (100 / $daysInMonth / 2);
                                        @endphp
                                        <div class="absolute bottom-0 h-2 w-px bg-cyan-500/60 z-0 pointer-events-none overflow-visible" style="left: {{ $todayPercent }}%; transform: translateX(-50%);">
                                            <div class="absolute -top-1 left-1/2 -translate-x-1/2 text-[10px] font-bold text-cyan-600 dark:text-cyan-400 whitespace-nowrap bg-white dark:bg-zinc-950 px-1 rounded z-40">
                                                TODAY
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Epic Rows with Squad Breakdown -->
                @foreach($epics as $epic)
                    @php
                        // Use pivot data for each squad's dates and story points
                        $squadWork = [];
                        foreach($epic->squads as $squad) {
                            // If squads are filtered, only include selected squads
                            if (!empty($this->selected_squads) && !in_array($squad->id, $this->selected_squads)) {
                                continue;
                            }
                            
                            $squadStartDate = $squad->pivot->start_date ? \Carbon\Carbon::parse($squad->pivot->start_date) : null;
                            $squadEndDate = $squad->pivot->end_date ? \Carbon\Carbon::parse($squad->pivot->end_date) : null;
                            
                            // Only include squad work that overlaps with the selected date range
                            if ($squadStartDate && $squadEndDate) {
                                $overlapsWithRange = $squadStartDate <= $this->date_range->end() && $squadEndDate >= $this->date_range->start();
                                if (!$overlapsWithRange) {
                                    continue;
                                }
                            }
                            
                            $storyCount = $epic->stories()->where('squad_id', $squad->id)->count();
                            $squadWork[$squad->id] = [
                                'squad' => $squad,
                                'start_date' => $squadStartDate,
                                'end_date' => $squadEndDate,
                                'story_count' => $storyCount,
                            ];
                        }

                        // Skip epic if no squad work overlaps with selected date range
                        if (empty($squadWork)) {
                            continue;
                        }
                    @endphp

                    <!-- Compact Epic Header Row -->
                    <div class="flex border-b-2 border-zinc-300 dark:border-zinc-600 bg-zinc-50 dark:bg-zinc-900">
                        <div class="flex-shrink-0 py-1.5 px-3 border-r border-zinc-200 dark:border-zinc-700 sticky left-0 z-10 bg-zinc-50 dark:bg-zinc-900 shadow-[2px_0_4px_rgba(0,0,0,0.05)]" :class="sidebarCollapsed ? 'w-48 lg:w-72' : 'w-48'" x-cloak>
                            <flux:tooltip :content="$epic->title" class="w-full">
                                <a href="/epics/{{ $epic->id }}/edit" wire:navigate class="block hover:text-blue-600 dark:hover:text-blue-400">
                                    <div class="text-sm font-bold truncate text-zinc-900 dark:text-zinc-100">{{ $epic->title }}</div>
                                </a>
                            </flux:tooltip>
                        </div>
                        <div class="flex-1 flex relative">
                            <!-- Empty space for epic header -->
                        </div>
                    </div>

                    <!-- Squad Rows -->
                    @foreach($squadWork as $work)
                        <div class="flex border-b border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors">
                            <div class="flex-shrink-0 p-1.5 border-r border-zinc-200 dark:border-zinc-800 pl-6 sticky left-0 z-10 bg-white dark:bg-zinc-950 hover:bg-zinc-50 dark:hover:bg-zinc-800 shadow-[2px_0_4px_rgba(0,0,0,0.05)]" :class="sidebarCollapsed ? 'w-48 lg:w-72' : 'w-48'" x-cloak>
                                <div class="flex items-center gap-2">
                                    <div class="h-2 w-2 rounded-full flex-shrink-0" style="background-color: {{ $work['squad']->color }}"></div>
                                    <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ $work['squad']->name }}</span>
                                </div>
                                @if($work['start_date'] && $work['end_date'])
                                <div class="flex items-center gap-2 flex-nowrap whitespace-nowrap mt-1">
                                    <div class="text-[10px] text-zinc-500 dark:text-zinc-400">
                                        {{ $work['start_date']->format('M j') }} - {{ $work['end_date']->format('M j') }}
                                    </div>
                                </div>
                                @endif
                            </div>
                            <div class="flex-1 flex relative" style="min-height: 50px;">
                                @if($work['start_date'] && $work['end_date'])
                                    @php
                                        $labelShown = false;
                                        $firstVisibleMonth = null;
                                    @endphp
                                    @foreach($months as $monthIndex => $month)
                                        <div class="flex-1 min-w-40 border-r border-zinc-200 dark:border-zinc-700 relative">
                                            @php
                                                $monthStart = $month['start'];
                                                $monthEnd = $month['end'];
                                                $squadStart = $work['start_date'];
                                                $squadEnd = $work['end_date'];
                                                
                                                // Check if squad's work overlaps with this month AND the selected date range
                                                $overlapsWithSelectedRange = $squadStart <= $this->date_range->end() && $squadEnd >= $this->date_range->start();
                                                $overlaps = ($squadStart <= $monthEnd && $squadEnd >= $monthStart) && $overlapsWithSelectedRange;
                                                
                                                if ($overlaps) {
                                                    // Track the first visible month for rounded corners
                                                    if ($firstVisibleMonth === null) {
                                                        $firstVisibleMonth = $monthIndex;
                                                    }
                                                    
                                                    // Calculate position and width within the month
                                                    $displayStart = max($squadStart, $monthStart);
                                                    $displayEnd = min($squadEnd, $monthEnd);
                                                    
                                                    // Calculate days from month start
                                                    $daysInMonth = $monthStart->daysInMonth;
                                                    $daysFromStart = $monthStart->diffInDays($displayStart);
                                                    $duration = $displayStart->diffInDays($displayEnd) + 1;
                                                    
                                                    // Convert to percentage
                                                    $leftPercent = ($daysFromStart / $daysInMonth) * 100;
                                                    $widthPercent = ($duration / $daysInMonth) * 100;
                                                    
                                                    // Show label on first segment with enough space (at least 15% width for monthly view)
                                                    $hasEnoughSpace = $widthPercent >= 15;
                                                    $shouldShowLabel = !$labelShown && $hasEnoughSpace;
                                                    if ($shouldShowLabel) {
                                                        $labelShown = true;
                                                    }
                                                    
                                                    $isFirstVisibleMonth = $monthIndex === $firstVisibleMonth;
                                                    // Check if this is the last month
                                                    $isLastMonth = $squadEnd <= $monthEnd;
                                                }
                                                
                                                // Check if today is in this month
                                                $today = \Carbon\Carbon::today();
                                                $isTodayInMonth = $today >= $monthStart && $today <= $monthEnd;
                                            @endphp
                                            
                                            @if($isTodayInMonth)
                                                @php
                                                    $daysInMonth = $monthStart->daysInMonth;
                                                    $daysFromStart = $monthStart->diffInDays($today);
                                                    $todayPercent = (($daysFromStart / $daysInMonth) * 100) + (100 / $daysInMonth / 2);
                                                @endphp
                                                <div class="absolute top-0 bottom-0 w-px bg-cyan-500/60 z-0 pointer-events-none" style="left: {{ $todayPercent }}%"></div>
                                            @endif
                                            
                                            @if($overlaps)
                                                <flux:dropdown position="top" align="start">
                                                    <button type="button" class="absolute top-2 bottom-2 rounded-lg flex items-center justify-center gap-2 px-2 text-white text-xs font-medium shadow-sm hover:shadow-md transition-shadow cursor-pointer"
                                                        style="left: {{ $leftPercent }}%; width: {{ $widthPercent }}%; background-color: {{ $work['squad']->color }}; {{ !$isFirstVisibleMonth ? 'border-top-left-radius: 0; border-bottom-left-radius: 0;' : '' }} {{ !$isLastMonth ? 'border-top-right-radius: 0; border-bottom-right-radius: 0;' : '' }}">
                                                    </button>
                                                    <x-roadmap.epic-squad-popover :epic="$epic" :work="$work" :squadStart="$squadStart" :squadEnd="$squadEnd" />
                                                </flux:dropdown>
                                            @endif
                                        </div>
                                    @endforeach
                                @endif
                            </div>
                        </div>
                    @endforeach
                @endforeach
            @endif
            
            </div>
        </div>

        <!-- Legend -->
        <div class="mt-6 flex flex-wrap items-center gap-4 text-sm">
            <div class="font-semibold">Legend:</div>
            @foreach($squads as $squad)
                <div class="flex items-center gap-2">
                    <div class="h-4 w-4 rounded" style="background-color: {{ $squad->color }}"></div>
                    <span>{{ $squad->name }}</span>
                </div>
            @endforeach
        </div>
    @endif
</div>

