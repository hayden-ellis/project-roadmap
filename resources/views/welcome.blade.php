<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Project Roadmap</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600&display=swap" rel="stylesheet" />

        <!-- Styles / Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>
    <body class="min-h-screen bg-gradient-to-b from-zinc-50 to-zinc-100 dark:from-zinc-900 dark:to-zinc-950 text-zinc-900 dark:text-zinc-100">
        <!-- Header Navigation -->
        <header class="w-full border-b border-zinc-200 dark:border-zinc-800 bg-white/80 dark:bg-zinc-900/80 backdrop-blur-sm sticky top-0 z-50">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <img src="{{ asset('roadmap-icon.png') }}" alt="Project Roadmap" class="h-8 w-8">
                        <span class="text-xl font-bold text-zinc-900 dark:text-zinc-100">Project Roadmap</span>
                    </div>
                    @if (Route::has('login'))
                        <nav class="flex items-center gap-4">
                            @auth
                                <a
                                    href="{{ route('now') }}"
                                    class="inline-flex items-center px-4 py-2 bg-cyan-500 dark:bg-cyan-500 text-white dark:text-white rounded-lg hover:bg-cyan-600 dark:hover:bg-cyan-400 transition-colors font-medium text-sm"
                                >
                                    Open App
                                </a>
                            @else
                                <a
                                    href="{{ route('login') }}"
                                    class="inline-flex items-center px-4 py-2 text-zinc-700 dark:text-zinc-300 hover:text-zinc-900 dark:hover:text-zinc-100 transition-colors font-medium text-sm"
                                >
                                    Log in
                                </a>

                                @if (Route::has('register'))
                                    <a
                                        href="{{ route('register') }}"
                                        class="inline-flex items-center px-4 py-2 bg-cyan-500 dark:bg-cyan-400/80 text-white dark:text-white rounded-lg hover:bg-cyan-600 dark:hover:bg-cyan-400 transition-colors font-medium text-sm"
                                    >
                                        Get Started
                                    </a>
                                @endif
                            @endauth
                        </nav>
                    @endif
                </div>
            </div>
        </header>

        <!-- Hero Section -->
        <div class="w-full py-16 sm:py-20 lg:py-24">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center max-w-3xl mx-auto">
                    <h1 class="text-4xl sm:text-5xl lg:text-6xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100 leading-tight">
                        Plan the quarter.<br>
                        <span class="bg-gradient-to-r from-cyan-600 to-sky-500 bg-clip-text text-transparent dark:from-cyan-400 dark:to-sky-300">Staff every epic.</span><br>
                        Watch the math check out.
                    </h1>
                    <p class="mt-6 text-lg sm:text-xl text-zinc-600 dark:text-zinc-400 leading-relaxed">
                        Capacity planning for engineering teams. Put epics in quarters, allocate engineers
                        week by week, and let points, timelines, and over-allocation warnings fall out of
                        the plan instead of a spreadsheet.
                    </p>
                    <p class="mt-4 text-xs text-zinc-500 dark:text-zinc-500">
                        Every number is derived. Nothing goes stale.
                    </p>
                    <div class="mt-10 flex items-center justify-center gap-4 flex-wrap">
                        @auth
                            <a
                                href="{{ route('now') }}"
                                class="inline-flex items-center px-6 py-3 bg-cyan-500 dark:bg-cyan-500 text-white dark:text-white rounded-lg hover:bg-cyan-600 dark:hover:bg-cyan-400 transition-colors font-semibold text-base shadow-lg"
                            >
                                Go to Your Dashboard
                            </a>
                        @else
                            <a
                                href="{{ route('register') }}"
                                class="inline-flex items-center px-6 py-3 bg-cyan-500 dark:bg-cyan-400/80 text-white dark:text-white rounded-lg hover:bg-cyan-600 dark:hover:bg-cyan-500 transition-colors font-semibold text-base shadow-lg"
                            >
                                Get Started Free
                            </a>
                            <a
                                href="{{ route('login') }}"
                                class="inline-flex items-center px-6 py-3 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 border border-zinc-200 dark:border-zinc-700 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors font-semibold text-base"
                            >
                                Sign In
                            </a>
                        @endauth
                    </div>
                </div>
            </div>
        </div>

        <!-- Calendar Preview Section -->
        <div class="w-full py-12 sm:py-16">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center mb-12">
                    <h2 class="text-3xl sm:text-4xl font-bold text-zinc-900 dark:text-zinc-100">
                        One plan. Three questions answered.
                    </h2>
                    <p class="mt-4 text-lg text-zinc-600 dark:text-zinc-400">
                        What are we building, who is actually on it, and does it fit?
                    </p>
                </div>

                <x-welcome.calendar />
            </div>
        </div>

        <!-- Features Section -->
        <div class="w-full py-16 sm:py-20 bg-white dark:bg-zinc-900">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <div class="text-center">
                        <div class="inline-flex items-center justify-center w-12 h-12 rounded-lg bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 mb-4">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-semibold text-zinc-900 dark:text-zinc-100 mb-2">Now</h3>
                        <p class="text-zinc-600 dark:text-zinc-400">See what every engineer is on this week, and pause, staff, or finish work from one screen.</p>
                    </div>
                    <div class="text-center">
                        <div class="inline-flex items-center justify-center w-12 h-12 rounded-lg bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400 mb-4">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-semibold text-zinc-900 dark:text-zinc-100 mb-2">Planning</h3>
                        <p class="text-zinc-600 dark:text-zinc-400">An engineer-by-week grid. Drop people onto epics and capacity totals update as you go.</p>
                    </div>
                    <div class="text-center">
                        <div class="inline-flex items-center justify-center w-12 h-12 rounded-lg bg-purple-100 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400 mb-4">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-semibold text-zinc-900 dark:text-zinc-100 mb-2">Matrix</h3>
                        <p class="text-zinc-600 dark:text-zinc-400">Epics against squads for the quarter, with over-allocation flagged before it bites.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="w-full py-8 border-t border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center text-sm text-zinc-600 dark:text-zinc-400">
                    <p>&copy; {{ date('Y') }} Project Roadmap. Built with Laravel & Livewire.</p>
                </div>
            </div>
        </footer>

        @fluxScripts
    </body>
</html>
