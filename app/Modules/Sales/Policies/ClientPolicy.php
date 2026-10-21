<?php

declare(strict_types=1);

namespace App\Modules\Sales\Policies;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\ClientFile;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Support\CareTeams;

/**
 * Clients. Admins see and do everything. After the first sale a client
 * belongs to the Upsell side: the person who looks after them, the people
 * who help and the Managers of that department see the whole client (every
 * sale, the files, the saved text). Front sales does not: the person who
 * closed the first sale hands the client over and adds the contract from
 * their lead, and a front Manager sees a client only until it is handed over.
 */
final class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->isGlobalAdmin()) {
            return true;
        }

        $brand = app(CurrentBrand::class)->get();

        if ($brand === null || ! $user->isActive()) {
            return false;
        }

        return $user->isManagerIn($brand)
            || CareTeams::includes($user, $brand)
            || Client::query()->inCareOf($user)->exists();
    }

    public function view(User $user, Client $client): bool
    {
        if ($user->isGlobalAdmin()) {
            return true;
        }

        return $this->inCurrentBrand($client) && Client::query()->visibleTo($user)->whereKey($client->getKey())->exists();
    }

    /** Bring clients in from a CSV or Excel file: Admins. */
    public function import(User $user): bool
    {
        return $user->isGlobalAdmin();
    }

    /** The whole client: upsells, files, saved text. */
    public function care(User $user, Client $client): bool
    {
        return $user->isGlobalAdmin() || ($this->inCurrentBrand($client) && $client->isInCareOf($user));
    }

    /**
     * Send the client to an Upsell department. Before that: whoever closed a
     * sale for them, or that person's Manager. Afterwards (moving them to
     * another department): a Manager of the department they are in.
     */
    public function handOver(User $user, Client $client): bool
    {
        if ($user->isGlobalAdmin()) {
            return true;
        }

        if (! $this->inCurrentBrand($client)) {
            return false;
        }

        return $client->isHandedOver() ? $this->leads($user, $client) : $this->soldTo($user, $client);
    }

    /** Choose who looks after the client: a Manager of their department. */
    public function assign(User $user, Client $client): bool
    {
        return $user->isGlobalAdmin() || ($this->inCurrentBrand($client) && $this->leads($user, $client));
    }

    /** Add or remove the people who help: the Manager, or the person who looks after the client. */
    public function share(User $user, Client $client): bool
    {
        return $this->assign($user, $client) || ($this->inCurrentBrand($client) && $client->owner_id === $user->getKey() && $user->isMemberOf($client->brand_id));
    }

    public function recordUpsell(User $user, Client $client): bool
    {
        return ! $client->isClosed() && $this->care($user, $client);
    }

    /** Close: the person who looks after the client, their Manager or an Admin. */
    public function close(User $user, Client $client): bool
    {
        return $this->share($user, $client);
    }

    public function reopen(User $user, Client $client): bool
    {
        return $this->assign($user, $client);
    }

    /** Add a file: the Upsell side, and front sales for the contract of the sale they closed. */
    public function addFile(User $user, Client $client): bool
    {
        return $this->care($user, $client) || ($this->inCurrentBrand($client) && $this->soldTo($user, $client));
    }

    /** Open a file: the Upsell side every file; front sales the ones added from a lead they may see. */
    public function viewFile(User $user, Client $client, ClientFile $file): bool
    {
        if (! $this->filed($client, $file)) {
            return false;
        }

        if ($this->care($user, $client)) {
            return true;
        }

        return $this->inCurrentBrand($client)
            && ($file->uploaded_by === $user->getKey() || ($file->leadId() !== null && $this->soldTo($user, $client, $file->leadId())));
    }

    /** Remove a file: whoever added it, the Manager of the department or an Admin. */
    public function deleteFile(User $user, Client $client, ClientFile $file): bool
    {
        if (! $this->filed($client, $file)) {
            return false;
        }

        return $this->assign($user, $client)
            || ($file->uploaded_by === $user->getKey() && $this->viewFile($user, $client, $file));
    }

    /** Is the file in this client's storage (added to the client, or to a lead that became them)? */
    private function filed(Client $client, ClientFile $file): bool
    {
        return ClientFile::query()->forBrand((int) $client->brand_id)->ofClient($client)->whereKey($file->getKey())->exists();
    }

    /** A Manager of the Upsell department the client is in. */
    private function leads(User $user, Client $client): bool
    {
        return $client->department_id !== null
            && $user->isManagerIn($client->brand_id)
            && $user->managesDepartment($client->department_id);
    }

    /** Did this person (or, for a Manager, their department) close a sale for the client? */
    private function soldTo(User $user, Client $client, ?int $leadId = null): bool
    {
        return $user->isMemberOf($client->brand_id)
            && Sale::query()->visibleTo($user)
                ->where('client_id', $client->getKey())
                ->when($leadId !== null, fn ($query) => $query->where('lead_id', $leadId))
                ->exists();
    }

    private function inCurrentBrand(Client $client): bool
    {
        return app(CurrentBrand::class)->is($client->brand_id);
    }
}
