<?php

declare(strict_types=1);

namespace App\Modules\Meta\Jobs;

use App\Modules\Meta\Exceptions\MetaException;
use App\Modules\Meta\Models\MetaSource;
use App\Modules\Meta\Support\MetaGraph;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Subscribes Pages to the webhook (leads, messages), one call to Meta each,
 * after "Connect" or "Refresh Pages" has already answered. A Page already
 * subscribed to every field is skipped unless `$force`. Each Page keeps its
 * own result: "Leads arriving", or Meta's reason on the Integrations screen.
 */
final class SubscribePages implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** @param list<int> $pageIds */
    public function __construct(public readonly array $pageIds, public readonly bool $force = false) {}

    public function handle(MetaGraph $graph): void
    {
        $fields = MetaSource::FIELDS;
        sort($fields);

        foreach (MetaSource::query()->whereKey($this->pageIds)->get() as $page) {
            $subscribed = (array) ($page->subscribed_fields ?? []);
            sort($subscribed);

            if (! $this->force && $page->subscribed_at !== null && $page->error === null && $subscribed === $fields) {
                continue;
            }

            try {
                $graph->subscribe($page);
                $page->update(['subscribed_fields' => MetaSource::FIELDS, 'subscribed_at' => now(), 'error' => null]);
            } catch (MetaException $exception) {
                $page->update(['error' => mb_substr($exception->getMessage(), 0, 500)]);
            }
        }
    }
}
