<?php

declare(strict_types=1);

namespace App\Modules\Core\Support\Ai;

/**
 * One free AI service the assistant can ask (docs/LIVECHAT_PLAN.md, "AI: free, replaceable"). `Ai` tries each ready
 * provider in turn, so a second service can stand in when the first is at its free limit.
 */
interface AiProvider
{
    /** The company's name, as the privacy page and the log say it. */
    public function name(): string;

    /** Whether its key is set. */
    public function ready(): bool;

    /**
     * The answer to `$text`, written the way `$instructions` say.
     *
     * @throws AiUnavailable when it cannot answer now (no key, at its limit, away); the message is for the log
     */
    public function answer(string $instructions, string $text, int $maxTokens): string;
}
