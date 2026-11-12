<?php

declare(strict_types=1);

namespace App\Modules\Chat\Jobs;

use App\Modules\Chat\Models\ChatSession;
use App\Modules\Chat\Models\ChatVisitor;
use App\Modules\Chat\Support\LiveChat;
use App\Modules\Chat\Support\VisitorLocation;
use App\Modules\Core\Support\RunSoon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Finds where a visitor's address is, right after the answer (the visitor never waits for it), and notes it on the chat's
 * visitor or the browser on the website. The country a CDN header gave stays.
 */
final class LocateVisitor implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    /** @param  'visitor'|'session'  $kind */
    public function __construct(public readonly string $kind, public readonly int $id, public readonly string $ip) {}

    public static function soon(ChatVisitor|ChatSession $row, ?string $ip): void
    {
        if ($ip === null || $ip === '' || ! LiveChat::geoReady()) {
            return;
        }

        RunSoon::dispatch(new self($row instanceof ChatVisitor ? 'visitor' : 'session', (int) $row->getKey(), $ip));
    }

    public function handle(): void
    {
        $row = $this->kind === 'visitor'
            ? ChatVisitor::query()->withoutGlobalScopes()->find($this->id)
            : ChatSession::query()->withoutGlobalScopes()->find($this->id);

        // The browser moved to another address meanwhile: that one is asked for in its own turn.
        if ($row === null || $row->getAttribute('ip') !== $this->ip) {
            return;
        }

        $found = VisitorLocation::lookup($this->ip);

        if ($found === null) {
            return;
        }

        $row->forceFill([
            'country' => $row->getAttribute('country') ?? $found['country'],
            'region' => $found['region'],
            'city' => $found['city'],
            'isp' => $found['isp'],
            'timezone' => $found['timezone'],
            'is_proxy' => $found['is_proxy'],
        ])->save();
    }
}
