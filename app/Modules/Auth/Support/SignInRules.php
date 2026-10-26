<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

use App\Modules\Auth\Enums\NetworkRule;
use App\Modules\Auth\Enums\PhoneRule;
use App\Modules\Auth\Models\SignInAddress;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Settings\SignInSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * The sign-in rules as they stand: the rule for everybody (SignInSettings)
 * and the office and blocked addresses. Every request of a signed-in person
 * is checked against them (EnforceSignInRules), so they are kept in the cache
 * as one entry, dropped by forget() on every change. The tables come with an
 * update: until an Admin clicks "Update system" nothing is enforced, and
 * LIQUIFY_SIGN_IN_RULES=false in .env switches the rules off when an Admin
 * has locked everybody out.
 */
final readonly class SignInRules
{
    private const CACHE_KEY = 'sign-in-rules';

    /**
     * @param  list<string>  $office
     * @param  list<string>  $blocked
     */
    public function __construct(
        public NetworkRule $network = NetworkRule::Anywhere,
        public PhoneRule $phones = PhoneRule::Any,
        public array $office = [],
        public array $blocked = [],
    ) {}

    public static function ready(): bool
    {
        static $ready = false;

        return $ready = $ready || Schema::hasTable('sign_in_devices');
    }

    /** Whether the rules are checked at all. */
    public static function enforced(): bool
    {
        return (bool) config('liquify.sign_in_rules') && self::ready();
    }

    public static function current(): self
    {
        if (! self::ready()) {
            return new self;
        }

        /** @var array{network: string, phones: string, office: list<string>, blocked: list<string>} $stored */
        $stored = Cache::rememberForever(self::CACHE_KEY, function (): array {
            try {
                $settings = app(SignInSettings::class);
                [$network, $phones] = [$settings->network, $settings->phones];
            } catch (Throwable) {
                // The settings migration has not run yet: as before the update.
                [$network, $phones] = [NetworkRule::Anywhere->value, PhoneRule::Any->value];
            }

            $addresses = SignInAddress::query()->get(['kind', 'address']);

            return [
                'network' => $network,
                'phones' => $phones,
                'office' => $addresses->where('kind', SignInAddress::OFFICE)->pluck('address')->values()->all(),
                'blocked' => $addresses->where('kind', SignInAddress::BLOCKED)->pluck('address')->values()->all(),
            ];
        });

        return new self(
            NetworkRule::tryFrom($stored['network']) ?? NetworkRule::Anywhere,
            PhoneRule::tryFrom($stored['phones']) ?? PhoneRule::Any,
            $stored['office'],
            $stored['blocked'],
        );
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The same rules with another rule for everybody, to try a change before it is saved.
     *
     * @param  list<string>|null  $office
     * @param  list<string>|null  $blocked
     */
    public function with(?NetworkRule $network = null, ?PhoneRule $phones = null, ?array $office = null, ?array $blocked = null): self
    {
        return new self($network ?? $this->network, $phones ?? $this->phones, $office ?? $this->office, $blocked ?? $this->blocked);
    }

    public function networkFor(User $user): NetworkRule
    {
        return self::ready() ? ($this->ownNetwork($user) ?? $this->network) : NetworkRule::Anywhere;
    }

    public function phonesFor(User $user): PhoneRule
    {
        return self::ready() ? ($this->ownPhones($user) ?? $this->phones) : PhoneRule::Any;
    }

    /** The person's own network rule, null when they follow the rule for everybody. */
    public function ownNetwork(User $user): ?NetworkRule
    {
        return NetworkRule::tryFrom((string) $user->getAttribute('sign_in_network'));
    }

    public function ownPhones(User $user): ?PhoneRule
    {
        return PhoneRule::tryFrom((string) $user->getAttribute('sign_in_phones'));
    }

    public function isOffice(?string $ip): bool
    {
        return self::matches($ip, $this->office);
    }

    public function isBlocked(?string $ip): bool
    {
        return self::matches($ip, $this->blocked);
    }

    /** @param  list<string>  $addresses */
    public static function matches(?string $ip, array $addresses): bool
    {
        return $ip !== null && $ip !== '' && $addresses !== [] && IpUtils::checkIp($ip, $addresses);
    }

    /**
     * A typed address or range in the form it is stored ("203.0.113.7",
     * "203.0.113.0/24", "2001:db8::/64"), or null when it is neither.
     */
    public static function normalise(string $typed): ?string
    {
        $typed = trim($typed);
        [$ip, $bits] = str_contains($typed, '/') ? explode('/', $typed, 2) : [$typed, null];

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 128 : 32;

        if ($bits === null) {
            return $ip;
        }

        if (! ctype_digit($bits) || (int) $bits < 8 || (int) $bits > $max) {
            return null;
        }

        return $ip.'/'.(int) $bits;
    }
}
