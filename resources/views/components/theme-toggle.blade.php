{{-- Flux's own toggle: `$flux.dark` is a setter that writes the same
     `$flux.appearance` Flux stores, and applyAppearance (wrapped in
     partials/head) mirrors the result into the `appearance` cookie the
     layout reads -- so the next server render is born in the right theme.

     The icon shows the *destination*, not the current state (moon while
     light), and swaps on the `dark` class rather than on Alpine, so it is
     already correct at first paint. No x-cloak, no flicker. --}}
<flux:tooltip :content="__('Toggle dark mode')" kbd="D" position="bottom">
    <flux:button
        x-data
        x-on:click="$flux.dark = ! $flux.dark"
        {{-- The `kbd` hint has to be real, so bind D globally -- but not while
             the user is typing one into a field. --}}
        x-on:keydown.window.d="
            if ($event.metaKey || $event.ctrlKey || $event.altKey) return;
            if ($event.target.isContentEditable) return;
            if (/^(input|textarea|select)$/i.test($event.target.tagName)) return;
            $flux.dark = ! $flux.dark;
        "
        variant="subtle"
        square
        :aria-label="__('Toggle dark mode')"
        data-test="theme-toggle"
    >
        <flux:icon.moon variant="micro" class="size-5 dark:hidden" />
        <flux:icon.sun variant="micro" class="hidden size-5 dark:block" />
    </flux:button>
</flux:tooltip>
