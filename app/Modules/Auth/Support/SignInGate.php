<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

use App\Modules\Auth\Enums\DeviceKind;
use App\Modules\Auth\Enums\DeviceStatus;
use App\Modules\Auth\Enums\NetworkRule;
use App\Modules\Auth\Enums\PhoneRule;
use App\Modules\Auth\Enums\SignInRefusal;
use App\Modules\Auth\Models\SignInDevice;
use App\Modules\Auth\Models\User;
use App\Modules\Core\Enums\GlobalRole;
use App\Modules\Core\Enums\MembershipStatus;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

/**
 * Decides whether a person may sign in, or stay signed in, from where they
 * are: a blocked address is refused to everybody, the office rule asks for
 * one of the office addresses, and on a phone or tablet the phone rule
 * applies (a blocked phone is refused whatever the rule). Called by Fortify
 * once the password is right, and by EnforceSignInRules on every request.
 */
final class SignInGate
{
    /** Session key: what the person signed in on, so a phone asking for the desktop site later stays a phone. */
    public const SESSION_DEVICE = 'sign_in.device';

    /** Session key: why the person was signed out, shown on the sign-in page until they sign in. */
    public const SESSION_REFUSED = 'sign_in.refused';

    /** What the request comes from, keeping the kind the person signed in with. */
    public static function deviceOf(Request $request): Device
    {
        $device = Device::of($request);
        $signedInOn = $request->hasSession() ? DeviceKind::tryFrom((string) $request->session()->get(self::SESSION_DEVICE)) : null;

        return $signedInOn !== null && $signedInOn->isMobile() && ! $device->kind->isMobile()
            ? new Device($signedInOn, $device->name)
            : $device;
    }

    /** This browser's own random name (the lq_device cookie), when it keeps one. */
    public static function tokenOf(Request $request): ?string
    {
        $token = $request->cookies->get(SignInDevice::COOKIE);

        return is_string($token) && strlen($token) >= 32 ? $token : null;
    }

    /**
     * Why this person may not be here, or null when they may. Changes nothing,
     * so Sign-in security asks it before saving a rule that would lock out the
     * Admin who is changing it.
     */
    public function refusal(User $user, ?string $ip, Device $device, ?string $token, ?SignInRules $rules = null): ?SignInRefusal
    {
        $rules ??= SignInRules::current();

        if ($rules->isBlocked($ip)) {
            return SignInRefusal::AddressBlocked;
        }

        if ($rules->networkFor($user) === NetworkRule::Office && ! $rules->isOffice($ip)) {
            return SignInRefusal::OutsideOffice;
        }

        if (! $device->kind->isMobile()) {
            return null;
        }

        $rows = $token !== null
            ? SignInDevice::query()->where('token_hash', SignInDevice::hashOf($token))->get(['user_id', 'status'])
            : collect();

        if ($rows->contains(fn (SignInDevice $row): bool => $row->status === DeviceStatus::Blocked)) {
            return SignInRefusal::PhoneBlocked;
        }

        return match ($rules->phonesFor($user)) {
            PhoneRule::Any => null,
            PhoneRule::None => SignInRefusal::NoPhones,
            PhoneRule::Approved => match (true) {
                $token === null => SignInRefusal::PhoneUnknown,
                $rows->contains(fn (SignInDevice $row): bool => (int) $row->user_id === (int) $user->getKey() && $row->status === DeviceStatus::Approved) => null,
                default => SignInRefusal::PhoneWaiting,
            },
        };
    }

    /**
     * The decision for this request, with what follows from it: the phone is
     * noted (a new one waiting for approval tells the Admins), and a refusal
     * goes into the sign-in log.
     */
    public function enforce(User $user, Request $request, Device $device, bool $signingIn): ?SignInRefusal
    {
        $token = self::tokenOf($request);
        $refusal = $this->refusal($user, $request->ip(), $device, $token);

        if ($device->kind->isMobile() && $token !== null) {
            $this->notePhone($user, $request, $device, $token, $refusal);
        }

        if ($refusal !== null) {
            Audit::log('auth.refused', $user, [
                'reason' => $refusal->value,
                'device' => $device->kind->value,
                'device_name' => $device->name,
                'during' => $signingIn ? 'sign-in' : 'session',
            ], 'Sign-in refused: '.mb_strtolower($refusal->getLabel()), causer: $user, logName: 'auth');
        }

        return $refusal;
    }

    private function notePhone(User $user, Request $request, Device $device, string $token, ?SignInRefusal $refusal): void
    {
        // Only a phone that got in or waits for approval is listed; one refused for another reason is only logged.
        if (! in_array($refusal, [null, SignInRefusal::PhoneWaiting], true)) {
            return;
        }

        $row = SignInDevice::query()->firstOrNew(['token_hash' => SignInDevice::hashOf($token), 'user_id' => $user->getKey()]);
        $was = $row->exists ? $row->status : null;

        if (! in_array($was, [DeviceStatus::Approved, DeviceStatus::Blocked], true)) {
            $row->status = $refusal === SignInRefusal::PhoneWaiting ? DeviceStatus::Waiting : DeviceStatus::Seen;
        }

        // A phone already known is written again only when something changed or every few minutes.
        if ($row->exists && ! $row->isDirty('status') && $row->last_ip === $request->ip() && $row->last_seen_at?->gt(now()->subMinutes(5))) {
            return;
        }

        $row->fill([
            'kind' => $device->kind,
            'name' => $device->name,
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
            'first_ip' => $row->first_ip ?? $request->ip(),
            'last_ip' => $request->ip(),
            'last_seen_at' => now(),
        ])->save();

        if ($row->status === DeviceStatus::Waiting && $was !== DeviceStatus::Waiting) {
            $this->tellAdmins($user, $device);
        }
    }

    private function tellAdmins(User $user, Device $device): void
    {
        // IT looks after Sign-in security too (27 Oct 2026).
        $admins = User::query()
            ->whereIn('role', [GlobalRole::SuperAdmin->value, GlobalRole::Admin->value, GlobalRole::It->value])
            ->where('status', MembershipStatus::Active->value)
            ->get();

        Notification::send($admins, new AppNotification(
            title: $user->name.' asks to use a '.mb_strtolower($device->kind->getLabel()),
            body: $device->name.'. Approve it on Sign-in security, or block it.',
            status: 'warning',
            url: route('admin.sign-in'),
            icon: $device->kind->icon(),
            actionLabel: 'Review',
        ));
    }
}
