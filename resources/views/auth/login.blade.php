<x-guest-layout>
    <div class="min-h-screen flex flex-col sm:justify-center items-center pt-12 sm:pt-16 bg-zinc-50 dark:bg-zinc-900">
        <div class="mb-6">
            <a href="/">
                <img src="{{ asset('roadmap-icon.png') }}" alt="Project Roadmap" class="h-16 w-16">
            </a>
        </div>

        <div class="w-full sm:max-w-md px-6 py-8 bg-white dark:bg-zinc-800 shadow-lg overflow-hidden sm:rounded-lg border border-zinc-200 dark:border-zinc-700">
            <livewire:auth.login />
        </div>
    </div>
</x-guest-layout>
