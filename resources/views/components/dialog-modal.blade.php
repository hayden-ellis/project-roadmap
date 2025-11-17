@props(['id' => null, 'maxWidth' => null])

<x-modal :id="$id" :maxWidth="$maxWidth" {{ $attributes }}>
    <div class="px-6 py-4">
        <div class="text-lg font-medium text-zinc-900 dark:text-zinc-100">
            {{ $title }}
        </div>

        <div class="mt-4 text-sm text-zinc-600 dark:text-zinc-400">
            {{ $content }}
        </div>
    </div>

    <div class="flex flex-row justify-end px-6 py-4 bg-zinc-100 dark:bg-zinc-900/50 text-end">
        {{ $footer }}
    </div>
</x-modal>
