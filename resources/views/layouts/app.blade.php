<x-layouts.app.header :title="isset($title) ? trim((string) $title) : null">
    @isset($header)
        <x-slot:header>{{ $header }}</x-slot:header>
    @endisset

    {{ $slot }}
</x-layouts.app.header>
