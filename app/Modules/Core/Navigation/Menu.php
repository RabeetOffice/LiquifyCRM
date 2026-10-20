<?php

declare(strict_types=1);

namespace App\Modules\Core\Navigation;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Resources\Support\BrandResources;
use App\Modules\Sales\Enums\ClientStage;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Support\CareTeams;
use App\Modules\Sales\Support\ClientCare;
use App\Modules\System\Support\SystemUpdater;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sidebar menus by role, from the "Menus by role" table: nobody sees an item
 * they cannot use. Brand screens live under /b/{brand}; the admin area is
 * for Super Admins and Admins.
 *
 * @phpstan-type MenuItem array{label: string, icon: string, url: string, active: bool, badge: ?string}
 * @phpstan-type MenuGroup array{label: string, items: list<MenuItem>}
 */
final class Menu
{
    /**
     * @return list<MenuGroup>
     */
    public static function for(User $user, ?Brand $brand, string $currentRoute): array
    {
        return match (true) {
            $brand !== null => self::brandMenu($user, $brand, $currentRoute),
            // The admin area's menu is for Admins only: a Manager or Agent never sees it, even without a brand.
            $user->isGlobalAdmin() => self::adminMenu($user, $currentRoute),
            default => [],
        };
    }

    /**
     * The brand whose menu a Manager or Agent gets on a screen outside any
     * brand (their profile, security): the brand they were last in, else
     * their first one. Admins get the admin menu there instead (null).
     */
    public static function homeBrand(User $user): ?Brand
    {
        if ($user->isGlobalAdmin()) {
            return null;
        }

        $brands = $user->accessibleBrands();
        $last = session('last_brand_id');

        $brand = $brands->first(fn (Brand $brand): bool => (int) $brand->getKey() === (int) $last) ?? $brands->first();

        return $brand instanceof Brand ? $brand : null;
    }

    /** @return list<MenuGroup> */
    private static function brandMenu(User $user, Brand $brand, string $currentRoute): array
    {
        $agent = ! $user->isGlobalAdmin() && $user->isAgentIn($brand);
        // An Agent on a front sales team sees the team's leads, not only their own.
        $team = $agent && $user->frontDepartmentIds($brand) !== [];
        $isActive = fn (string ...$routes): bool => in_array($currentRoute, $routes, true);
        $params = ['brand' => $brand->slug];

        // Front sales counts the free leads waiting to be taken; another Agent their own open leads.
        $queueCount = function () use ($user, $agent, $team): ?string {
            $count = $agent && ! $team
                ? Lead::query()->workedBy($user)->open()->unarchived()->count()
                : Lead::query()->visibleTo($user)->unassigned()->count();

            return $count > 0 ? (string) $count : null;
        };

        $unread = function () use ($user, $agent): ?string {
            // The chats the Inbox lists (none of an archived lead), so the count always matches what is there.
            $count = (int) Conversation::query()->listedFor($user)
                // An Agent counts their own chats and the free ones, not a colleague's.
                ->when($agent, fn (Builder $query): Builder => $query->whereHas('lead', fn (Builder $lead): Builder => $lead->where(fn (Builder $mine): Builder => $mine->where('assigned_to', $user->getKey())->orWhereNull('assigned_to'))))
                ->sum('unread_count');

            return $count > 0 ? (string) $count : null;
        };

        // Someone who only works on an Upsell team has clients, not leads: the lead
        // screens stay out of their way unless a lead was given to them all the same.
        $clientsOnly = CareTeams::worksClientsOnly($user, $brand);
        $clientRoutes = ['brand.clients.index', 'brand.clients.show', 'brand.dashboard'];

        // The clients an Upsell team received that nobody looks after yet.
        $waiting = function () use ($user): ?string {
            $count = ClientCare::ready() ? Client::query()->visibleTo($user)->inStage(ClientStage::Waiting)->count() : 0;

            return $count > 0 ? (string) $count : null;
        };

        $main = $clientsOnly
            ? [self::item($agent ? 'My clients' : 'Clients', 'heroicon-o-user-group', route('brand.clients.index', $params), $isActive(...$clientRoutes), $agent ? null : $waiting)]
            : [
                self::item('Dashboard', 'heroicon-o-home', route('brand.dashboard', $params), $isActive('brand.dashboard')),
                self::item($agent && ! $team ? 'My leads' : 'Leads', 'heroicon-o-inbox-arrow-down', route('brand.leads.index', $params), $isActive('brand.leads.index', 'brand.leads.create', 'brand.leads.show'), $queueCount),
                self::item('Pipeline', 'heroicon-o-view-columns', route('brand.leads.pipeline', $params), $isActive('brand.leads.pipeline')),
                self::item('Inbox', 'heroicon-o-chat-bubble-left-right', route('brand.inbox', $params), $isActive('brand.inbox'), $unread),
                self::item('Follow-ups', 'heroicon-o-calendar-days', route('brand.follow-ups', $params), $isActive('brand.follow-ups')),
            ];

        // The brand's templates and material, for everyone in it (19 Oct 2026).
        if (BrandResources::ready()) {
            $main[] = self::item('Resources', 'heroicon-o-folder-open', route('brand.resources.index', $params), $isActive('brand.resources.index'));
        }

        $groups = [['label' => 'Main menu', 'items' => $main]];

        $sales = [self::item($agent ? 'My target' : 'Sales', 'heroicon-o-trophy', route('brand.sales', $params), $isActive('brand.sales'))];

        if ($user->isGlobalAdmin() || $user->isManagerIn($brand)) {
            $sales[] = self::item('Targets', 'heroicon-o-flag', route('brand.targets', $params), $isActive('brand.targets'));

            if (! $clientsOnly) {
                $sales[] = self::item('Clients', 'heroicon-o-user-group', route('brand.clients.index', $params), $isActive('brand.clients.index', 'brand.clients.show'), $waiting);
            }

            $sales[] = self::item($clientsOnly ? 'How clients are shared' : 'Distribute leads', 'heroicon-o-arrows-right-left', route('brand.leads.distribute', $params), $isActive('brand.leads.distribute'));
        } elseif (! $clientsOnly && (CareTeams::includes($user, $brand) || (ClientCare::ready() && Client::query()->inCareOf($user)->exists()))) {
            // Someone on an Upsell team works from their clients, not from leads.
            $sales[] = self::item('My clients', 'heroicon-o-user-group', route('brand.clients.index', $params), $isActive('brand.clients.index', 'brand.clients.show'));
        }

        $groups[] = ['label' => 'Sales', 'items' => $sales];

        if ($user->isGlobalAdmin() || $user->isManagerIn($brand)) {
            $groups[] = ['label' => 'Team', 'items' => [
                self::item('Departments', 'heroicon-o-rectangle-group', route('brand.departments.index', $params), $isActive('brand.departments.index', 'brand.departments.create', 'brand.departments.edit')),
                self::item('Members', 'heroicon-o-users', route('brand.members.index', $params), $isActive('brand.members.index', 'brand.members.create')),
            ]];
        }

        if ($user->isGlobalAdmin() || $user->isManagerIn($brand)) {
            $settings = [
                self::item('Websites', 'heroicon-o-globe-alt', route('brand.sites.index', $params), $isActive('brand.sites.index', 'brand.sites.connect', 'brand.sites.show')),
            ];

            // Logs and the bin of deleted leads are for Admins only.
            if ($user->isGlobalAdmin()) {
                $settings[] = self::item('Logs', 'heroicon-o-clipboard-document-list', route('brand.logs', $params), $isActive('brand.logs'));
                $settings[] = self::item('Bin', 'heroicon-o-trash', route('brand.leads.bin', $params), $isActive('brand.leads.bin'));
                $settings[] = self::item('Email settings', 'heroicon-o-envelope', route('brand.email-settings', $params), $isActive('brand.email-settings'));
            }

            $groups[] = ['label' => 'Settings', 'items' => $settings];
        }

        // Moving to the admin area or another brand is done from the switcher at the top of the sidebar.
        return $groups;
    }

    /** @return list<MenuGroup> */
    private static function adminMenu(User $user, string $currentRoute): array
    {
        $isActive = fn (string ...$routes): bool => in_array($currentRoute, $routes, true);

        // Admins and Super Admins see the same menu: the only difference between
        // them is that a Super Admin cannot be edited or removed by anyone else.
        return [
            ['label' => 'Main menu', 'items' => [
                self::item('Overview', 'heroicon-o-home', route('admin.dashboard'), $isActive('admin.dashboard')),
                self::item('Sales dashboard', 'heroicon-o-trophy', route('admin.sales'), $isActive('admin.sales')),
                self::item('Brands', 'heroicon-o-building-storefront', route('admin.brands.index'), $isActive('admin.brands.index', 'admin.brands.create', 'admin.brands.edit')),
                self::item('People', 'heroicon-o-users', route('admin.people.index'), $isActive('admin.people.index', 'admin.people.create', 'admin.people.edit')),
            ]],
            ['label' => 'Settings', 'items' => [
                self::item('Integrations', 'heroicon-o-puzzle-piece', route('admin.integrations'), $isActive('admin.integrations')),
                self::item('Logs', 'heroicon-o-clipboard-document-list', route('admin.logs'), $isActive('admin.logs')),
                self::item('Updates', 'heroicon-o-arrow-up-circle', route('admin.updates'), $isActive('admin.updates'), fn (): ?string => SystemUpdater::flag()['available'] ? 'New' : null),
                self::item('System', 'heroicon-o-server-stack', route('admin.system'), $isActive('admin.system')),
            ]],
        ];
    }

    /**
     * @param  (Closure(): ?string)|null  $badge
     * @return MenuItem
     */
    private static function item(string $label, string $icon, string $url, bool $active, ?Closure $badge = null): array
    {
        return [
            'label' => $label,
            'icon' => $icon,
            'url' => $url,
            'active' => $active,
            'badge' => $badge !== null ? $badge() : null,
        ];
    }
}
