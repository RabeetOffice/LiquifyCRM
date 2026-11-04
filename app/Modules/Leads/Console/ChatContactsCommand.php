<?php

declare(strict_types=1);

namespace App\Modules\Leads\Console;

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Leads\Actions\TakeContactFromChat;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Nightly: open Messenger and Instagram leads of the last two months that
 * still have no email or phone read what the person wrote in their chat
 * (TakeContactFromChat), so chats from before 4 Nov 2026 and messages that
 * came while the lead had a closed twin are caught up too. New messages are
 * read as they arrive.
 */
final class ChatContactsCommand extends Command
{
    protected $signature = 'leads:chat-contacts {--days=60 : How far back to look}';

    protected $description = 'Put the email and phone number people wrote in their chat on their lead';

    public function handle(TakeContactFromChat $take, CurrentBrand $current): int
    {
        $count = 0;
        $brands = [];

        Lead::query()
            ->acrossBrands()
            ->open()
            ->whereIn('source_type', [LeadSource::Messenger->value, LeadSource::Instagram->value])
            ->where('created_at', '>=', now()->subDays(max(1, (int) $this->option('days'))))
            ->where(fn ($query) => $query->whereNull('email')->orWhereNull('phone')->orWhere('email', '')->orWhere('phone', ''))
            ->chunkById(100, function (Collection $leads) use ($take, $current, &$count, &$brands): void {
                foreach ($leads as $lead) {
                    $brand = $brands[$lead->brand_id] ??= Brand::query()->find($lead->brand_id);
                    $chats = Conversation::query()->acrossBrands()->where('lead_id', $lead->getKey())->pluck('id');

                    if ($brand === null || $chats->isEmpty()) {
                        continue;
                    }

                    // What the person wrote, oldest first: the first email and number are most likely their own.
                    $text = Message::query()->whereIn('conversation_id', $chats)->where('direction', Message::IN)->oldest('id')->limit(50)->pluck('body')->filter()->implode("\n");

                    if ($current->within($brand, fn (): array => $take->handle($lead, $text, $brand)) !== []) {
                        $count++;
                    }
                }
            });

        $this->info('Contact details taken from the chat for '.$count.' '.str('lead')->plural($count).'.');

        return self::SUCCESS;
    }
}
