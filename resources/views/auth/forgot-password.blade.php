<x-guest-layout>
    <div class="min-h-screen flex flex-col sm:justify-center items-center pt-12 sm:pt-16 bg-zinc-50 dark:bg-zinc-900">
        <div class="mb-6">
            <a href="/">
                <img src="{{ asset('roadmap-icon.png') }}" alt="Project Roadmap" class="h-16 w-16">
            </a>
        </div>

        <div class="w-full sm:max-w-md px-6 py-8 bg-white dark:bg-zinc-800 shadow-lg overflow-hidden sm:rounded-lg border border-zinc-200 dark:border-zinc-700">
            <div class="mb-6">
                <h2 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ __('Forgot your password?') }}</h2>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                    {{ __('No problem. Enter your email below and we will send you a password reset link.') }}
                </p>
            </div>

            <x-auth-session-status :status="session('status')" />

            <x-validation-errors class="mb-4" />

            <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-6">
                @csrf

                <flux:input
                    label="{{ __('Email address') }}"
                    type="email"
                    id="email"
                    name="email"
                    :value="old('email')"
                    required
                    autofocus
                    autocomplete="username"
                    placeholder="email@example.com"
                />

                <flux:button variant="primary" type="submit" class="w-full">
                    {{ __('Email Password Reset Link') }}
                </flux:button>
            </form>

            <div class="mt-6 text-center text-sm text-zinc-600 dark:text-zinc-400">
                {{ __('Remember your password?') }}
                <flux:link href="{{ route('login') }}">{{ __('Back to login') }}</flux:link>
            </div>
        </div>
    </div>
</x-guest-layout>
