<?php

namespace App\Support;

use App\Http\Controllers\DirectoryController;
use Illuminate\Contracts\Auth\Authenticatable;

class AdminMenu
{
    /**
     * Sidebar menu for authenticated users (directories + OL Bot links).
     *
     * @return list<array<string, mixed>>
     */
    public static function forUser(?Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }

        $items = [];

        foreach (DirectoryController::definitions() as $key => $config) {
            if (! self::mayViewDirectory($user, $key)) {
                continue;
            }

            $items[] = [
                'key' => $config['key'],
                'title' => $config['title'],
                'icon' => $config['icon'] ?? null,
            ];
        }

        foreach (self::olBotItems() as $item) {
            if (! $user->can($item['permission'])) {
                continue;
            }

            $items[] = [
                'key' => $item['key'],
                'title' => $item['title'],
                'icon' => $item['icon'] ?? null,
                'href' => $item['href'],
            ];
        }

        return $items;
    }

    /**
     * First reachable admin URL for the user (directories or OL Bot).
     */
    public static function homeUrl(?Authenticatable $user): string
    {
        $menu = self::forUser($user);

        if ($menu === []) {
            return route('login');
        }

        $first = $menu[0];

        if (! empty($first['href']) && is_string($first['href'])) {
            return $first['href'];
        }

        return route('directories.page', ['directory' => $first['key']]);
    }

    /**
     * OL Bot menu definitions with required permissions.
     *
     * @return list<array{key: string, title: string, icon: string, href: string, permission: string}>
     */
    public static function olBotItems(): array
    {
        return [
            [
                'key' => 'ol-bot-analytics',
                'title' => 'OL Analytics',
                'icon' => '📊',
                'href' => '/ol-bot/analytics',
                'permission' => 'ol-bot.analytics',
            ],
            [
                'key' => 'ol-bot-catalog',
                'title' => 'OL Catalog',
                'icon' => '🏠',
                'href' => '/ol-bot/analytics?tab=catalog',
                'permission' => 'ol-bot.catalog',
            ],
            [
                'key' => 'ol-bot-templates',
                'title' => 'OL Templates',
                'icon' => '🤖',
                'href' => '/ol-bot/templates',
                'permission' => 'ol-bot.templates',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function olBotPermissionNames(): array
    {
        $names = array_map(
            static fn (array $item): string => $item['permission'],
            self::olBotItems(),
        );
        $names[] = 'ol-bot.templates.edit';

        return array_values(array_unique($names));
    }

    /**
     * @param  Authenticatable  $user
     */
    private static function mayViewDirectory(Authenticatable $user, string $directoryKey): bool
    {
        return $user->can('directory.'.$directoryKey);
    }
}
