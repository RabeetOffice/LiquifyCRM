<?php

declare(strict_types=1);

namespace App\Modules\Auth\Enums;

use App\Modules\Core\Contracts\HasLabel;

/** Why SignInGate turned somebody away: the label is for Admins, the message for the person. */
enum SignInRefusal: string implements HasLabel
{
    case AddressBlocked = 'address_blocked';
    case OutsideOffice = 'outside_office';
    case NoPhones = 'no_phones';
    case PhoneWaiting = 'phone_waiting';
    case PhoneBlocked = 'phone_blocked';
    case PhoneUnknown = 'phone_unknown';

    public function getLabel(): string
    {
        return match ($this) {
            self::AddressBlocked => 'Blocked address',
            self::OutsideOffice => 'Outside the office network',
            self::NoPhones => 'Phones not allowed',
            self::PhoneWaiting => 'Phone waiting for approval',
            self::PhoneBlocked => 'Blocked phone',
            self::PhoneUnknown => 'Phone keeps no cookies',
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::AddressBlocked => 'LiquifyCRM cannot be opened from this internet connection. Ask your Admin if you think this is wrong.',
            self::OutsideOffice => 'You can sign in only from the office network. Connect to the office internet, or ask your Admin.',
            self::NoPhones => 'You cannot use LiquifyCRM on a phone or tablet. Sign in on a computer, or ask your Admin.',
            self::PhoneWaiting => 'This phone needs your Admin\'s approval first. They have been told: sign in again once they approve it.',
            self::PhoneBlocked => 'This phone has been blocked. Sign in on a computer, or ask your Admin.',
            self::PhoneUnknown => 'This browser keeps no cookies, so the phone cannot be approved. Turn cookies on for this site and try again.',
        };
    }
}
