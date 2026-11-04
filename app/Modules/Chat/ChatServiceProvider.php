<?php

declare(strict_types=1);

namespace App\Modules\Chat;

use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Policies\ChatWidgetPolicy;
use App\Modules\Core\ModuleServiceProvider;

/**
 * Live chat on the brands' websites (user request, 3 Nov 2026; plan in docs/LIVECHAT_PLAN.md). A visitor's
 * chat is a `Conversation` of the Inbox with `channel = livechat`; this module owns the widget, the visitor
 * and the settings screen.
 */
final class ChatServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Chat';

    protected array $policies = [
        ChatWidget::class => ChatWidgetPolicy::class,
    ];
}
