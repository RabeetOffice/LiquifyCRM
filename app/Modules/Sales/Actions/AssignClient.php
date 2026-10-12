<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Support\Audit;
use App\Modules\Departments\Models\Department;
use App\Modules\Sales\Models\Client;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * Inside the Upsell department: choose who looks after a client (or take
 * them back, so they wait again), and add or remove the people who help.
 * Everyone on a client sees it and records their own upsells there.
 */
final class AssignClient
{
    public function handle(Client $client, ?User $owner, User $by): Client
    {
        if ($owner !== null) {
            $this->mustBeOnTheTeam($client, $owner);
        }

        $previous = $client->owner_id;

        if ($previous === $owner?->getKey()) {
            return $client;
        }

        $client->forceFill(['owner_id' => $owner?->getKey()])->save();

        if ($owner !== null) {
            $client->helpers()->detach($owner->getKey());
        }

        Audit::log(
            'clients.assigned',
            $client,
            ['from' => $previous, 'to' => $owner?->getKey()],
            $owner !== null ? 'Client given to '.$owner->name : 'Client is waiting for a person again',
            brand: (int) $client->brand_id,
            department: $client->department_id,
            causer: $by,
            logName: 'sales',
        );

        if ($owner !== null && ! $owner->is($by)) {
            $this->tell($client, $owner, 'Client given to you: '.$client->name, $by->name.' chose you to look after this client.');
        }

        return $client;
    }

    public function addHelper(Client $client, User $helper, User $by): void
    {
        $this->mustBeOnTheTeam($client, $helper);

        if ($client->owner_id === $helper->getKey() || $client->helpers()->where('users.id', $helper->getKey())->exists()) {
            return;
        }

        $client->helpers()->attach($helper->getKey(), ['added_by' => $by->getKey()]);

        Audit::log('clients.helper_added', $client, ['user_id' => $helper->getKey()], $helper->name.' now also works on this client', brand: (int) $client->brand_id, department: $client->department_id, causer: $by, logName: 'sales');

        if (! $helper->is($by)) {
            $this->tell($client, $helper, 'You were added to a client: '.$client->name, $by->name.' added you. You can open the client and record your own upsells.');
        }
    }

    public function removeHelper(Client $client, User $helper, User $by): void
    {
        if ($client->helpers()->detach($helper->getKey()) > 0) {
            Audit::log('clients.helper_removed', $client, ['user_id' => $helper->getKey()], $helper->name.' no longer works on this client', brand: (int) $client->brand_id, department: $client->department_id, causer: $by, logName: 'sales');
        }
    }

    private function mustBeOnTheTeam(Client $client, User $person): void
    {
        $department = $client->department_id !== null ? Department::query()->forBrand((int) $client->brand_id)->find($client->department_id) : null;

        if ($department === null) {
            throw new InvalidArgumentException('Hand the client to an Upsell department first.');
        }

        if (! $department->hasMember($person)) {
            throw new InvalidArgumentException($person->name.' is not in '.$department->name.'.');
        }
    }

    private function tell(Client $client, User $person, string $title, string $body): void
    {
        $brand = Brand::query()->find($client->brand_id);

        if ($brand !== null) {
            Notification::send($person, new AppNotification($title, $body, 'info', $brand->url('clients/'.$client->getKey()), mail: true, icon: 'heroicon-o-user-group', actionLabel: 'Open client'));
        }
    }
}
