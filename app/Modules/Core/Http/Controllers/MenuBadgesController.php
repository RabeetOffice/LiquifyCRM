<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Navigation\Menu;
use App\Modules\Core\Support\CurrentBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The counts in the sidebar menu (Leads waiting, unread chats, clients
 * waiting), as the menu shows them now. app.js asks for them when something
 * changed (see Live), so they move without opening a screen (20 Oct 2026).
 * Keyed by each item's address, the same the sidebar draws.
 */
final class MenuBadgesController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $current = app(CurrentBrand::class);
        $brand = $current->get();

        // Outside a brand a Manager or Agent has the menu of their own brand, as the sidebar does.
        $home = $brand === null ? Menu::homeBrand($user) : null;
        $menu = $home !== null
            ? $current->within($home, fn (): array => Menu::for($user, $home, ''))
            : Menu::for($user, $brand, '');

        $badges = [];

        foreach ($menu as $group) {
            foreach ($group['items'] as $item) {
                $badges[$item['url']] = $item['badge'];
            }
        }

        return response()->json($badges)->header('Cache-Control', 'no-store');
    }
}
