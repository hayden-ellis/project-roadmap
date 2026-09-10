<?php

namespace App\Support;

/**
 * The primary nav, described once.
 *
 * The header navbar and the mobile drawer both render it, so the list lives
 * here rather than being typed twice and drifting apart. Flat for now: seven
 * items fit the bar. If it grows, the views already know how to render an
 * item with `children` as a dropdown / headed group.
 *
 * @phpstan-type NavLink array{key: string, label: string, icon: string, href: string, current: bool}
 */
final class Navigation
{
    /**
     * @return list<NavLink>
     */
    public static function items(): array
    {
        return [
            self::link('now', __('Now'), 'bolt', 'now', 'now'),
            self::link('matrix', __('Matrix'), 'squares-2x2', 'matrix', 'matrix'),
            self::link('epics', __('Epics'), 'rectangle-stack', 'epics.index', 'epics.*'),
            self::link('engineers', __('Engineers'), 'user-group', 'engineers.index', 'engineers.*'),
            self::link('squads', __('Squads'), 'users', 'squads.index', 'squads.*'),
            self::link('statuses', __('Statuses'), 'view-columns', 'statuses.index', 'statuses.*'),
            self::link('categories', __('Categories'), 'tag', 'categories.index', 'categories.*'),
        ];
    }

    /**
     * @return NavLink
     */
    private static function link(string $key, string $label, string $icon, string $route, string $pattern): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'href' => route($route),
            'current' => request()->routeIs($pattern),
        ];
    }
}
