<?php

declare(strict_types=1);

namespace App\Modules\Meta\Exceptions;

use RuntimeException;

/** An error answered by the Graph API, or a Meta step that cannot continue. */
final class MetaException extends RuntimeException
{
    public function __construct(string $message, int $code = 0, public readonly ?int $subcode = null)
    {
        parent::__construct($message, $code);
    }

    /** Code 190: the token expired or was revoked, so Facebook must be connected again. */
    public function tokenExpired(): bool
    {
        return $this->getCode() === 190;
    }

    /**
     * Why a reply was not sent, for the team. The answers Meta gives most
     * often are put in plain words, with Meta's own words after them;
     * anything else is Meta's message as it is.
     */
    public function forPeople(): string
    {
        $meta = ' Meta says: "'.$this->getMessage().'"';

        return match (true) {
            // Meta's own limit on the Page or Instagram account: Business Suite is blocked too, so it is not LiquifyCRM.
            $this->getCode() === 368 => 'Meta has blocked this account from sending messages for a while. It is Meta\'s limit, not LiquifyCRM: replies from Meta Business Suite are blocked too. It usually lifts by itself within a day or two; the Instagram app shows it under Settings, Account status.'.$meta,
            $this->tokenExpired() => 'The connection to Facebook has ended. An Admin clicks Connect again on the Integrations screen.',
            $this->getCode() === 10 && in_array($this->subcode, [2018278, 2534022], true) => 'The 24-hour reply window has closed. You can reply again after this person writes.',
            $this->getCode() === 551 => 'This person cannot get messages from this account right now (they may have blocked it or turned off messages).'.$meta,
            default => $this->getMessage(),
        };
    }
}
