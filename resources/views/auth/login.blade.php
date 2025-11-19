<x-guest-layout>
    <div class="min-h-screen flex flex-col sm:justify-center items-center pt-12 sm:pt-16 bg-zinc-50 dark:bg-zinc-900">
        <div class="mb-6">
            <a href="/">
                <img src="{{ asset('roadmap-icon.png') }}" alt="Project Roadmap" class="h-16 w-16">
            </a>
        </div>

        <div class="w-full sm:max-w-md px-6 py-8 bg-white dark:bg-zinc-800 shadow-lg overflow-hidden sm:rounded-lg border border-zinc-200 dark:border-zinc-700">
            <div class="mb-6">
                <h2 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Log in to your account</h2>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">Enter your email and password below to log in</p>
            </div>

            <!-- Session Status -->
            @session('status')
                <div class="mb-4 text-center font-medium text-sm text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-900/20 p-3 rounded-lg border border-green-200 dark:border-green-800">
                    {{ $value }}
                </div>
            @endsession

            <!-- Validation Errors -->
            <x-validation-errors class="mb-4" />

            <form method="POST" action="{{ route('login') }}" class="flex flex-col gap-6">
                @csrf

                <!-- Email Address -->
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

                <!-- Password -->
                <div class="relative">
                    <flux:input
                        label="{{ __('Password') }}"
                        type="password"
                        id="password"
                        name="password"
                        required
                        autocomplete="current-password"
                        placeholder="Password"
                    />

                    @if (Route::has('password.request'))
                        <flux:link class="absolute right-0 top-0 text-sm" href="{{ route('password.request') }}">
                            {{ __('Forgot your password?') }}
                        </flux:link>
                    @endif
                </div>

                <!-- Remember Me -->
                <flux:checkbox id="remember_me" name="remember" label="{{ __('Remember me') }}" />
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full">{{ __('Log in') }}</flux:button>
                </div>
            </form>

            <flux:separator text="or" class="my-6" />

            <flux:button href="{{ route('auth.google.redirect') }}" class="w-full">
                <x-slot name="icon">
                    <svg width="25" height="24" viewBox="0 0 25 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M23.06 12.25C23.06 11.47 22.99 10.72 22.86 10H12.5V14.26H18.42C18.16 15.63 17.38 16.79 16.21 17.57V20.34H19.78C21.86 18.42 23.06 15.6 23.06 12.25Z" fill="#4285F4"/>
                        <path d="M12.4997 23C15.4697 23 17.9597 22.02 19.7797 20.34L16.2097 17.57C15.2297 18.23 13.9797 18.63 12.4997 18.63C9.63969 18.63 7.20969 16.7 6.33969 14.1H2.67969V16.94C4.48969 20.53 8.19969 23 12.4997 23Z" fill="#34A853"/>
                        <path d="M6.34 14.0899C6.12 13.4299 5.99 12.7299 5.99 11.9999C5.99 11.2699 6.12 10.5699 6.34 9.90995V7.06995H2.68C1.93 8.54995 1.5 10.2199 1.5 11.9999C1.5 13.7799 1.93 15.4499 2.68 16.9299L5.53 14.7099L6.34 14.0899Z" fill="#FBBC05"/>
                        <path d="M12.4997 5.38C14.1197 5.38 15.5597 5.94 16.7097 7.02L19.8597 3.87C17.9497 2.09 15.4697 1 12.4997 1C8.19969 1 4.48969 3.47 2.67969 7.07L6.33969 9.91C7.20969 7.31 9.63969 5.38 12.4997 5.38Z" fill="#EA4335"/>
                    </svg>
                </x-slot>
                Continue with Google
            </flux:button>

            <div class="mt-6 space-x-1 text-center text-sm text-zinc-600 dark:text-zinc-400">
                First time?
                <flux:link href="{{ route('register') }}">Sign up for free</flux:link>
            </div>
        </div>
    </div>
</x-guest-layout>
