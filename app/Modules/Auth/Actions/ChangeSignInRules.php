<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Modules\Auth\Enums\NetworkRule;
use App\Modules\Auth\Enums\PhoneRule;
use App\Modules\Auth\Models\SignInAddress;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Settings\SignInSettings;
use App\Modules\Auth\Support\SignInRules;
use App\Modules\Core\Support\Audit;

/**
 * Every change to the sign-in rules: the rule for everybody, the office and
 * blocked addresses, and a person's own rule. Each one is logged and drops
 * the cached rules, so the very next request is checked against the change.
 * Whether a change would lock out the Admin making it is asked beforehand
 * (SignInSecurity, PersonForm), not here.
 */
final class ChangeSignInRules
{
    public function forEverybody(NetworkRule $network, PhoneRule $phones): void
    {
        $settings = app(SignInSettings::class);
        $before = ['network' => $settings->network, 'phones' => $settings->phones];

        $settings->network = $network->value;
        $settings->phones = $phones->value;
        $settings->save();
        SignInRules::forget();

        Audit::log('auth.rules_changed', null, ['before' => $before, 'after' => ['network' => $network->value, 'phones' => $phones->value]],
            'Sign-in rules for everybody: '.mb_strtolower($network->getLabel()).', '.mb_strtolower($phones->getLabel()), logName: 'auth');
    }

    public function forPerson(User $person, ?NetworkRule $network, ?PhoneRule $phones): void
    {
        $before = ['network' => $person->getAttribute('sign_in_network'), 'phones' => $person->getAttribute('sign_in_phones')];
        $after = ['network' => $network?->value, 'phones' => $phones?->value];

        if ($before === $after) {
            return;
        }

        $person->forceFill(['sign_in_network' => $network?->value, 'sign_in_phones' => $phones?->value])->save();

        Audit::log('auth.person_rules_changed', $person, ['before' => $before, 'after' => $after],
            'Own sign-in rules: '.($network?->getLabel() ?? 'network as for everybody').', '.mb_strtolower($phones?->getLabel() ?? 'phones as for everybody'), logName: 'auth');
    }

    public function addAddress(string $kind, string $address, ?string $label, User $admin): SignInAddress
    {
        $row = SignInAddress::query()->updateOrCreate(['kind' => $kind, 'address' => $address], ['label' => $label, 'created_by' => $admin->getKey()]);
        SignInRules::forget();

        Audit::log($kind === SignInAddress::OFFICE ? 'auth.office_address_added' : 'auth.address_blocked_added', null, ['address' => $address, 'label' => $label],
            ($kind === SignInAddress::OFFICE ? 'Office address added: ' : 'Address blocked: ').$address, logName: 'auth');

        return $row;
    }

    public function removeAddress(SignInAddress $row): void
    {
        $row->delete();
        SignInRules::forget();

        Audit::log($row->kind === SignInAddress::OFFICE ? 'auth.office_address_removed' : 'auth.address_unblocked', null, ['address' => $row->address],
            ($row->kind === SignInAddress::OFFICE ? 'Office address removed: ' : 'Address unblocked: ').$row->address, logName: 'auth');
    }
}
