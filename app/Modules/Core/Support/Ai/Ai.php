<?php

declare(strict_types=1);

namespace App\Modules\Core\Support\Ai;

use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The assistant (user request, 16 Nov 2026: "integrate Groq free AI", team helpers first): free AI that helps a person
 * of the team write and read, never one that talks to a customer by itself. Everything is optional and fails
 * quietly: without a key nothing is shown, and at a limit the person reads "busy, try again".
 *
 * The rules every caller keeps: text goes out only through `ContactMask` (names, email addresses and phone numbers
 * replaced by placeholders), what comes back is shown to the person who asked and sent or saved only by them, and a
 * new provider is named on the privacy page.
 */
final class Ai
{
    /** One person's questions a minute: a button pressed again and again must not use up everybody's free limit. */
    private const PER_PERSON_A_MINUTE = 8;

    public static function enabled(): bool
    {
        foreach (self::providers() as $provider) {
            if ($provider->ready()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ask the first provider that can answer.
     *
     * @throws AiUnavailable with words for the person who asked
     */
    public static function ask(string $instructions, string $text, User $by, int $maxTokens = 350): string
    {
        if (! self::enabled()) {
            throw new AiUnavailable('The assistant is not switched on.');
        }

        $person = 'ai:person:'.$by->getKey();

        if (RateLimiter::tooManyAttempts($person, self::PER_PERSON_A_MINUTE)) {
            throw new AiUnavailable('You have asked the assistant a lot in the last minute. Try again in a moment.');
        }

        RateLimiter::hit($person, 60);

        $today = 'ai:asked:'.now()->toDateString();
        Cache::add($today, 0, now()->addDays(2));

        if ((int) Cache::get($today, 0) >= max(1, (int) config('liquify.ai.daily_cap', 800))) {
            throw new AiUnavailable('The assistant has answered as much as it may today. It is back tomorrow.');
        }

        Cache::increment($today);

        foreach (self::providers() as $provider) {
            if (! $provider->ready()) {
                continue;
            }

            try {
                return $provider->answer($instructions, $text, $maxTokens);
            } catch (AiUnavailable $exception) {
                // Being away is not an error of ours: a line in the log once a minute, and the next provider is asked.
                if (Cache::add('ai:failed:'.$provider->name(), true, 60)) {
                    Log::warning('The assistant ('.$provider->name().'): '.$exception->getMessage());
                }
            }
        }

        throw new AiUnavailable('The assistant is busy right now. Try again in a minute.');
    }

    /** How many questions the CRM asked today, for whoever wants to see the free limit being used. */
    public static function askedToday(): int
    {
        return (int) Cache::get('ai:asked:'.now()->toDateString(), 0);
    }

    /** @return list<AiProvider> in the order they are tried */
    private static function providers(): array
    {
        return [app(GroqProvider::class)];
    }
}
