<?php

use App\Support\AtlassianLink;

it('reads the issue key from a browse URL', function () {
    expect(AtlassianLink::issueKey('https://acme.atlassian.net/browse/ROAD-42'))->toBe('ROAD-42');
});

it('reads the issue key from a browse URL with trailing noise', function () {
    expect(AtlassianLink::issueKey('https://acme.atlassian.net/browse/ROAD-42?atlOrigin=abc'))->toBe('ROAD-42')
        ->and(AtlassianLink::issueKey('https://acme.atlassian.net/browse/ROAD-42#comments'))->toBe('ROAD-42');
});

it('reads the issue key from a Product Discovery board URL', function () {
    $url = 'https://acme.atlassian.net/jira/polaris/projects/IDEAS/ideas/view/123456?selectedIssue=IDEAS-7';

    expect(AtlassianLink::issueKey($url))->toBe('IDEAS-7');
});

it('works on custom domains, not just atlassian.net', function () {
    expect(AtlassianLink::issueKey('https://jira.acme.dev/browse/ROAD-9'))->toBe('ROAD-9');
});

it('returns null when no key can be read', function () {
    expect(AtlassianLink::issueKey(null))->toBeNull()
        ->and(AtlassianLink::issueKey(''))->toBeNull()
        ->and(AtlassianLink::issueKey('https://acme.atlassian.net/jira/software/projects/ROAD/boards/1'))->toBeNull()
        ->and(AtlassianLink::issueKey('not a url'))->toBeNull();
});

it('does not mistake a lowercase path segment for a key', function () {
    expect(AtlassianLink::issueKey('https://acme.atlassian.net/browse/road-42'))->toBeNull();
});
