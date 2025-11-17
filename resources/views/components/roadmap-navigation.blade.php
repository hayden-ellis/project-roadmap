@props(['currentView' => 'timeline'])

<div class="flex gap-2">
    <flux:button 
        href="{{ route('roadmap') }}" 
        variant="{{ $currentView === 'calendar' ? 'primary' : 'ghost' }}" 
        size="sm" 
        wire:navigate>
        Calendar View
    </flux:button>
    <flux:button 
        href="{{ route('roadmap.timeline') }}" 
        variant="{{ $currentView === 'timeline' ? 'primary' : 'ghost' }}" 
        size="sm" 
        wire:navigate>
        Timeline View
    </flux:button>
</div>
