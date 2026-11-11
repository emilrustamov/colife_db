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
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function olBotItems(): array
    {
        return [
            [
                'key' => 'ol-bot-analytics',
                'title' => 'OL Analytics',
                'icon' => '📊',
                'href' => '/ol-bot/analytics',
            ],
            [
                'key' => 'ol-bot-catalog',
                'title' => 'OL Catalog',
                'icon' => '🏠',
                'href' => '/ol-bot/analytics?tab=catalog',
            ],
            [
                'key' => 'ol-bot-templates',
                'title' => 'OL Templates',
                'icon' => '🤖',
                'href' => '/ol-bot/templates',
            ],
        ];
    }

    /**
     * @param  Authenticatable  $user
     */
    private static function mayViewDirectory(Authenticatable $user, string $directoryKey): bool
    {
        if ($user->can('directories.view')) {
            return true;
        }

        return $user->can('directory.'.$directoryKey);
    }
}
