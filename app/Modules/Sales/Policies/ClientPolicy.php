<?php

declare(strict_types=1);

namespace App\Modules\Sales\Policies;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\Sale;

/**
 * Clients: Admins see every client of the brand, a Manager the clients
 * their own departments sold to. Agents work from their leads.
 */
final class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->isGlobalAdmin()) {
            return true;
        }

        $brand = app(CurrentBrand::class)->get();

        return $brand !== null && $user->isManagerIn($brand);
    }

    public function view(User $user, Client $client): bool
    {
        if ($user->isGlobalAdmin()) {
            return true;
        }

        return $this->viewAny($user)
            && app(CurrentBrand::class)->is($client->brand_id)
            && Sale::query()->visibleTo($user)->where('client_id', $client->getKey())->exists();
    }
}
