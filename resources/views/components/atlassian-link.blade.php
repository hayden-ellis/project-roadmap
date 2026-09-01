@props(['url', 'kind' => 'jira', 'interactive' => true])

@php
    use App\Support\AtlassianLink;

    // Blue is delivery, purple is discovery -- the same split Atlassian's own
    // UI draws between Jira and Product Discovery.
    $config = [
        'jira' => [
            'fallback' => 'Jira',
            'title' => 'Open in Jira',
            'classes' => 'bg-blue-500/10 text-blue-700 dark:text-blue-400',
        ],
        'idea' => [
            'fallback' => 'Idea',
            'title' => 'Open idea in Jira Product Discovery',
            'classes' => 'bg-purple-500/10 text-purple-700 dark:text-purple-400',
        ],
    ][$kind];

    $label = AtlassianLink::issueKey($url) ?? $config['fallback'];
    $tag = $interactive ? 'a' : 'span';
@endphp

{{-- Non-interactive on surfaces that are themselves one big anchor (the
     epics list card): an <a> cannot legally nest inside another <a>. --}}
<{{ $tag }}
    @if($interactive)
        href="{{ $url }}" target="_blank" rel="noopener noreferrer" x-on:click.stop
    @endif
    title="{{ $config['title'] }}"
    {{ $attributes->class([
        'inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium tabular-nums shrink-0',
        'hover:underline' => $interactive,
        $config['classes'],
    ]) }}>
    <flux:icon.arrow-top-right-on-square variant="micro" class="size-3" />
    {{ $label }}
</{{ $tag }}>
