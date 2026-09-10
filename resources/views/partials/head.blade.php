<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>{{ $title ?? 'Project Roadmap' }}</title>

<link rel="preconnect" href="https://fonts.bunny.net">
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<link rel="manifest" href="/site.webmanifest">
<link href="https://fonts.bunny.net/css?family=inter:400,500,600&display=swap" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])
<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/sort@3.x.x/dist/cdn.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/mask@3.x.x/dist/cdn.min.js"></script>
@fluxAppearance

{{-- Flux keeps the light/dark choice in localStorage, which the server
     cannot read. Without help every page would arrive as one theme and be
     corrected on the client, and wire:navigate copies the html attributes
     across before Flux runs again -- so anything with a colour transition
     animates the round trip as a flicker. Mirror the resolved choice into
     a cookie and the layout can render the right class to begin with.
     Installed here, before Alpine boots, because Flux captures
     applyAppearance at alpine:init. --}}
<script>
    (() => {
        const apply = window.Flux.applyAppearance

        const remember = () => {
            const mode = document.documentElement.classList.contains('dark') ? 'dark' : 'light'
            const secure = location.protocol === 'https:' ? '; Secure' : ''

            document.cookie = `appearance=${mode}; path=/; max-age=31536000; SameSite=Lax${secure}`
        }

        window.Flux.applyAppearance = (appearance) => { apply(appearance); remember() }

        remember()
    })()
</script>
