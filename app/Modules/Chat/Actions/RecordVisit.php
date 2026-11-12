<?php

declare(strict_types=1);

namespace App\Modules\Chat\Actions;

use App\Modules\Chat\Jobs\LocateVisitor;
use App\Modules\Chat\Models\ChatVisitor;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Support\VisitorToken;

/**
 * Somebody opens the chat on a website: their browser's token is found (or made, when it is new or was not
 * issued here) and the visit is noted. A visitor who comes back after half an hour counts as a new visit.
 */
final class RecordVisit
{
    private const NEW_VISIT_AFTER_MINUTES = 30;

    /**
     * @param  array{ip?: ?string, country?: ?string, kind?: ?string, browser?: ?string, os?: ?string}  $info
     */
    public function handle(ChatWidget $widget, ?string $token, ?string $sig, array $info): ChatVisitor
    {
        $visitor = VisitorToken::valid($token, $sig)
            ? ChatVisitor::query()->where('brand_id', $widget->brand_id)->where('visitor_token', (string) $token)->first()
            : null;

        $seen = [
            'ip' => $info['ip'] ?? null,
            'country' => $info['country'] ?? null,
            'device' => $info['kind'] ?? null,
            'browser' => $info['browser'] ?? null,
            'os' => $info['os'] ?? null,
            'last_seen_at' => now(),
        ];

        if ($visitor === null) {
            $visitor = ChatVisitor::query()->create([
                ...$seen,
                'brand_id' => $widget->brand_id,
                'chat_widget_id' => $widget->getKey(),
                'visitor_token' => VisitorToken::issue()['token'],
                'first_seen_at' => now(),
            ]);
            LocateVisitor::soon($visitor, $visitor->ip);

            return $visitor;
        }

        // A new address (or one never placed) is looked up again after the answer.
        $moved = ($info['ip'] ?? null) !== null && ($visitor->ip !== $info['ip'] || $visitor->city === null);

        $again = $visitor->last_seen_at === null || $visitor->last_seen_at->lt(now()->subMinutes(self::NEW_VISIT_AFTER_MINUTES));

        $visitor->forceFill([
            ...array_filter($seen, fn (mixed $value): bool => $value !== null),
            'visits_count' => $visitor->visits_count + ($again ? 1 : 0),
        ])->save();

        if ($moved && $again) {
            LocateVisitor::soon($visitor, $visitor->ip);
        }

        return $visitor;
    }
}
