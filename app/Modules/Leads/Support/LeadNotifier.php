<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\GlobalRole;
use App\Modules\Core\Enums\MembershipStatus;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * In-app bell plus email for the people who need to act on a lead: the
 * department's Managers for a new lead (Admins while it has no department),
 * the Agent when it is assigned. Front sales hears of every free lead it may
 * take (bell, sound and desktop alert, no email: one a lead would be too many).
 */
final class LeadNotifier
{
    public function newLead(Lead $lead): void
    {
        $lead->loadMissing(['brand', 'department']);
        /** @var Brand $brand */
        $brand = $lead->brand;

        $recipients = $lead->department
            ? $lead->department->managers()->get()
            : User::query()->whereIn('role', [GlobalRole::SuperAdmin->value, GlobalRole::Admin->value])->get();

        $body = $lead->source_type->getLabel().' · '.$brand->name.($lead->department ? ' · '.$lead->department->name : '');

        $this->send($recipients, 'New lead: '.$lead->name, $body, $this->url($lead), 'heroicon-o-inbox-arrow-down');
        $this->tellFrontSales($lead, $recipients, 'New lead: '.$lead->name, $body.'. Nobody has it yet: take it if you are free.');

        if ($brand->notificationEmails() !== []) {
            Notification::route('mail', $brand->notificationEmails())
                ->notify(new AppNotification('New lead: '.$lead->name, 'A new lead arrived for '.$brand->name.' from '.$lead->source_type->getLabel().'.', 'info', $this->url($lead), mail: true, actionLabel: 'Open lead'));
        }
    }

    public function assigned(Lead $lead, ?User $agent, ?User $by = null): void
    {
        if ($agent === null) {
            return;
        }

        $this->send(
            new Collection([$agent]),
            'Lead assigned to you: '.$lead->name,
            ($by->name ?? 'LiquifyCRM').' assigned this lead to you.',
            $this->url($lead),
            'heroicon-o-user-plus',
        );
    }

    public function sentToDepartment(Lead $lead, Department $department, ?User $by = null): void
    {
        $managers = $department->managers()->get();

        $this->send(
            $managers,
            'New lead for '.$department->name.': '.$lead->name,
            ($by->name ?? 'LiquifyCRM').' sent this lead to your department. Assign it to an Agent.',
            $this->url($lead),
            'heroicon-o-inbox-arrow-down',
        );

        $lead->setRelation('department', $department);
        $this->tellFrontSales($lead, $managers, 'New lead for '.$department->name.': '.$lead->name, ($by->name ?? 'LiquifyCRM').' sent it to your team. Nobody has it yet: take it if you are free.');
    }

    /**
     * Everyone of front sales who may take this free lead and has not been
     * told already: the Agents of its team, or, while it has no team, the
     * people of every front sales team of the brand.
     *
     * @param  Collection<int, User>  $told
     */
    private function tellFrontSales(Lead $lead, Collection $told, string $title, string $body): void
    {
        if ($lead->assigned_to !== null) {
            return;
        }

        $department = $lead->department;

        if ($department !== null && $department->isUpsell()) {
            return;
        }

        $teams = $department !== null
            ? new Collection([$department])
            : Department::query()->acrossBrands()->where('brand_id', $lead->brand_id)->get()->reject(fn (Department $team): bool => $team->isUpsell());

        $people = new Collection;

        foreach ($teams as $team) {
            $members = $department !== null ? $team->agents() : $team->users();

            $people = $people->merge($members
                ->where('users.status', MembershipStatus::Active->value)
                ->whereHas('memberships', fn (Builder $query): Builder => $query->withoutGlobalScopes()->where('brand_id', $lead->brand_id)->where('status', MembershipStatus::Active->value))
                ->get());
        }

        $people = $people->reject(fn (User $person): bool => $told->contains('id', $person->getKey()));

        $this->send($people, $title, $body, $this->url($lead), 'heroicon-o-hand-raised', mail: false);
    }

    public function url(Lead $lead): string
    {
        $lead->loadMissing('brand');

        return $lead->brand->url('leads/'.$lead->getKey());
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function send(Collection $users, string $title, string $body, string $url, string $icon, bool $mail = true): void
    {
        $users = $users->unique('id')->values();

        if ($users->isEmpty()) {
            return;
        }

        Notification::send($users, new AppNotification($title, $body, 'info', $url, mail: $mail, icon: $icon, actionLabel: 'Open lead'));
    }
}
