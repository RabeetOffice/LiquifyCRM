<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\AssignmentMode;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Support\Audit;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Actions\AutoAssignLead;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * Front sales is done: the client goes to an Upsell department. With a
 * person chosen, that person looks after the client from now on; without
 * one, a round-robin department gives the client to the next person in
 * turn and any other department keeps them waiting for its Manager. The
 * lead that was won says on its timeline where its client went.
 */
final class HandOverClient
{
    public function handle(Client $client, Department $department, ?User $owner, User $by): Client
    {
        if ((int) $department->brand_id !== (int) $client->brand_id || ! $department->isUpsell()) {
            throw new InvalidArgumentException('A client is handed to an Upsell department of the same brand.');
        }

        if ($owner !== null && ! $department->hasMember($owner)) {
            throw new InvalidArgumentException('That person is not in '.$department->name.'.');
        }

        $owner = DB::transaction(function () use ($client, $department, $owner, $by): ?User {
            $owner ??= $this->nextInTurn($department);

            // Shared out again inside the team it is already in: the day front sales handed it over stays.
            $sameTeam = $client->handed_over_at !== null && (int) $client->department_id === (int) $department->getKey();

            $client->forceFill([
                'department_id' => $department->getKey(),
                'owner_id' => $owner?->getKey(),
                'handed_over_at' => $sameTeam ? $client->handed_over_at : now(),
                'handed_over_by' => $sameTeam ? $client->handed_over_by : $by->getKey(),
            ])->save();

            if ($owner !== null) {
                $client->helpers()->detach($owner->getKey());
            }

            $text = 'Client handed to '.$department->name.($owner !== null ? ': '.$owner->name : '');
            $properties = ['client_id' => $client->getKey(), 'department_id' => $department->getKey(), 'owner_id' => $owner?->getKey()];

            Audit::log('clients.handed_over', $client, $properties, $text, brand: (int) $client->brand_id, department: $department, causer: $by, logName: 'sales');

            $leadIds = Sale::query()->forBrand((int) $client->brand_id)->where('client_id', $client->getKey())->whereNotNull('lead_id')->pluck('lead_id')->unique();

            foreach (Lead::query()->forBrand((int) $client->brand_id)->whereKey($leadIds)->get() as $lead) {
                Audit::log('leads.client_handed_over', $lead, $properties, $text, brand: (int) $client->brand_id, causer: $by, logName: 'leads');
            }

            return $owner;
        });

        $this->tell($client, $department, $owner, $by);

        return $client;
    }

    /** Round robin: whoever comes after the person that received the last one. */
    private function nextInTurn(Department $department): ?User
    {
        $locked = Department::query()->acrossBrands()->lockForUpdate()->find($department->getKey());

        if ($locked === null || $locked->assignment_mode !== AssignmentMode::RoundRobin) {
            return null;
        }

        $agents = app(AutoAssignLead::class)->agents($locked);
        $last = (int) $locked->last_assigned_user_id;
        $next = $agents->first(fn (User $agent): bool => (int) $agent->getKey() > $last) ?? $agents->first();

        if ($next !== null) {
            $locked->forceFill(['last_assigned_user_id' => $next->getKey()])->save();
        }

        return $next;
    }

    private function tell(Client $client, Department $department, ?User $owner, User $by): void
    {
        $brand = Brand::query()->find($client->brand_id);

        if ($brand === null) {
            return;
        }

        $worth = 'Bought so far: '.Money::format($client->lifetime_value, $brand->currency).'.';
        $url = $brand->url('clients/'.$client->getKey());

        if ($owner !== null) {
            if (! $owner->is($by)) {
                Notification::send($owner, new AppNotification('New client for you: '.$client->name, $by->name.' handed this client to you. '.$worth, 'success', $url, mail: true, icon: 'heroicon-o-user-group', actionLabel: 'Open client'));
            }

            return;
        }

        $managers = $department->managers()->get()->reject(fn (User $manager): bool => $manager->is($by));

        if ($managers->isNotEmpty()) {
            Notification::send($managers, new AppNotification('A client is waiting in '.$department->name.': '.$client->name, $by->name.' handed this client to your department. Choose who looks after them. '.$worth, 'info', $url, mail: true, icon: 'heroicon-o-user-group', actionLabel: 'Open client'));
        }
    }
}
