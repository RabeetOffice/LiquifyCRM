<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\GlobalRole;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Models\Lead;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * In-app bell plus email for the people who need to act on a lead: the
 * department's Managers for a new lead (Admins while it has no department),
 * the Agent when it is assigned.
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
        $this->send(
            $department->managers()->get(),
            'New lead for '.$department->name.': '.$lead->name,
            ($by->name ?? 'LiquifyCRM').' sent this lead to your department. Assign it to an Agent.',
            $this->url($lead),
            'heroicon-o-inbox-arrow-down',
        );
    }

    public function url(Lead $lead): string
    {
        $lead->loadMissing('brand');

        return $lead->brand->url('leads/'.$lead->getKey());
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function send(Collection $users, string $title, string $body, string $url, string $icon): void
    {
        $users = $users->unique('id')->values();

        if ($users->isEmpty()) {
            return;
        }

        Notification::send($users, new AppNotification($title, $body, 'info', $url, mail: true, icon: $icon, actionLabel: 'Open lead'));
    }
}
