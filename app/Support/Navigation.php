<?php

namespace App\Support;

/**
 * The primary nav, described once.
 *
 * The header navbar and the mobile drawer both render it, so the list lives
 * here rather than being typed twice and drifting apart. Each item is either
 * a link or a labelled group of links; the views decide how a group looks
 * (a dropdown in the header, a headed section in the drawer).
 *
 * @phpstan-type NavLink array{key: string, label: string, icon: string, href: string, current: bool}
 * @phpstan-type NavGroup array{key: string, label: string, icon: string, current: bool, children: list<NavLink>}
 */
final class Navigation
{
    /**
     * @return list<NavLink|NavGroup>
     */
    public static function items(): array
    {
        $manage = [
            self::link('epics', __('Epics'), 'rectangle-stack', 'epics.index', 'epics.*'),
            self::link('engineers', __('Engineers'), 'user-group', 'engineers.index', 'engineers.*'),
            self::link('squads', __('Squads'), 'users', 'squads.index', 'squads.*'),
            self::link('statuses', __('Statuses'), 'view-columns', 'statuses.index', 'statuses.*'),
            self::link('categories', __('Categories'), 'tag', 'categories.index', 'categories.*'),
        ];

        return [
            self::link('now', __('Now'), 'bolt', 'now', 'now'),
            self::link('matrix', __('Matrix'), 'squares-2x2', 'matrix', 'matrix'),
            [
                'key' => 'manage',
                'label' => __('Manage'),
                'icon' => 'adjustments-horizontal',
                'current' => collect($manage)->contains('current', true),
                'children' => $manage,
            ],
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
