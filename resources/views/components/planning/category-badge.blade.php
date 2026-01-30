@props(['category'])

<span
    {{ $attributes->class(['inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs']) }}
    style="background-color: {{ $category->color }}15; color: {{ $category->color }}"
>
    <span class="h-1.5 w-1.5 rounded-full" style="background-color: {{ $category->color }}"></span>
    {{ $category->name }}
</span>
