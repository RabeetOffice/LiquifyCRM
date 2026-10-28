<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Modules\Auth\Enums\DeviceStatus;
use App\Modules\Auth\Models\SignInDevice;
use App\Modules\Auth\Models\User;
use App\Modules\Core\Models\PushSubscription;
use App\Modules\Core\Support\Audit;
use App\Modules\Core\Support\WebPush;

/**
 * An Admin's word on a phone or tablet: approve it for the person who used
 * it, block it (for everybody who used that phone: the next request of any
 * of them signs them out), or forget it, so it is asked about again.
 */
final class DecideDevice
{
    public function approve(SignInDevice $device, User $admin): void
    {
        // Approving a blocked phone lifts the block for everybody who used it.
        if ($device->status === DeviceStatus::Blocked) {
            SignInDevice::query()->where('token_hash', $device->token_hash)->whereKeyNot($device->getKey())
                ->where('status', DeviceStatus::Blocked->value)
                ->update(['status' => DeviceStatus::Seen->value, 'decided_by' => $admin->getKey(), 'decided_at' => now()]);
        }

        $device->forceFill(['status' => DeviceStatus::Approved, 'decided_by' => $admin->getKey(), 'decided_at' => now()])->save();

        $this->log('auth.device_approved', $device, 'Phone approved: ');
    }

    public function block(SignInDevice $device, User $admin): void
    {
        SignInDevice::query()->where('token_hash', $device->token_hash)
            ->update(['status' => DeviceStatus::Blocked->value, 'decided_by' => $admin->getKey(), 'decided_at' => now()]);

        // Desktop alerts are not tied to a phone, so the people on it lose theirs; their other
        // browsers turn alerts back on by themselves on the next page they open.
        if (WebPush::ready()) {
            PushSubscription::query()
                ->whereIn('user_id', SignInDevice::query()->where('token_hash', $device->token_hash)->select('user_id'))
                ->delete();
        }

        $this->log('auth.device_blocked', $device, 'Phone blocked: ');
    }

    public function forget(SignInDevice $device): void
    {
        $this->log('auth.device_forgotten', $device, 'Phone removed from the list: ');

        $device->delete();
    }

    private function log(string $event, SignInDevice $device, string $prefix): void
    {
        Audit::log($event, $device->user, [
            'device' => $device->kind->value,
            'device_name' => $device->name,
        ], $prefix.$device->name.' of '.($device->user->name ?? 'a former member'), logName: 'auth');
    }
}
