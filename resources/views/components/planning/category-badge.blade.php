@props(['category'])

<span
    {{ $attributes->class(['inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-xs font-medium']) }}
    style="background-color: {{ $category->color }}25; color: {{ $category->color }};"
>
    <span class="h-2 w-2 rounded-full" style="background-color: {{ $category->color }}"></span>
    {{ $category->name }}
</span>
