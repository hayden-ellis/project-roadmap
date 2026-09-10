{{-- Jetstream's forms announce success by dispatching a Livewire event
     ("saved", "loggedOut"). Turn that into a Flux toast so every settings
     form confirms the same way. Renders nothing itself. --}}
@props(['on' => 'saved', 'text' => 'Saved.'])

<span x-data x-init="$wire.$on('{{ $on }}', () => $flux.toast({ text: @js(__($text)), variant: 'success' }))" hidden></span>
