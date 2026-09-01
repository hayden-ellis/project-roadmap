<?php

namespace App\Support;

/**
 * Reads an issue key out of a pasted Atlassian URL.
 *
 * Covers the two shapes people actually copy: the canonical
 * /browse/PROJ-123 page, and Jira Product Discovery's board view, where the
 * key hides in a selectedIssue query parameter. Anything else is still a
 * fine link to keep -- it just renders without a key.
 */
class AtlassianLink
{
    private const KEY_PATTERN = '[A-Z][A-Z0-9_]*-\d+';

    public static function issueKey(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        if (preg_match('~/browse/('.self::KEY_PATTERN.')(?=[/?#]|$)~', $url, $matches)) {
            return $matches[1];
        }

        if (preg_match('~[?&]selectedIssue=('.self::KEY_PATTERN.')(?=[&#]|$)~', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
