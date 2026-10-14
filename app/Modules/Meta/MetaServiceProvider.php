<?php

declare(strict_types=1);

namespace App\Modules\Meta;

use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Meta\Console\RefreshTokensCommand;
use App\Modules\Meta\Jobs\ProcessMetaEvent;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Policies\ConversationPolicy;
use App\Modules\Sites\Models\InboundEvent;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class MetaServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Meta';

    protected array $policies = [
        Conversation::class => ConversationPolicy::class,
    ];

    protected array $commands = [
        RefreshTokensCommand::class,
    ];

    protected function bootModule(): void
    {
        InboundEvent::processWith(ProcessMetaEvent::LEADGEN, ProcessMetaEvent::class);
        InboundEvent::processWith(ProcessMetaEvent::MESSAGE, ProcessMetaEvent::class);
        InboundEvent::processWith(ProcessMetaEvent::INSTAGRAM, ProcessMetaEvent::class);

        // 120 webhook calls a minute per Page; Meta retries anything refused.
        RateLimiter::for('meta-webhook', fn (Request $request): Limit => Limit::perMinute((int) config('liquify.meta.rate_limit', 120))
            ->by('meta:'.($request->input('entry.0.id') ?? $request->ip())));
    }
}
