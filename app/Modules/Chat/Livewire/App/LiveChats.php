<?php

declare(strict_types=1);

namespace App\Modules\Chat\Livewire\App;

use App\Modules\Brands\Models\Brand;
use App\Modules\Chat\Support\LiveChat;
use App\Modules\Meta\Livewire\App\Inbox;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/**
 * Every chat of the brand's websites, inside the Live chat tab (user request, 4 Nov 2026: "the chat of whoever writes
 * from the website must be inside Live chat too, so I do not have to open the Inbox"). It is the Inbox's screen, kept
 * to website chats; the Inbox keeps Messenger and Instagram.
 */
#[Layout('components.layouts.app')]
#[Title('Live chats')]
final class LiveChats extends Inbox
{
    public function mount(Brand $brand): void
    {
        abort_unless(LiveChat::ready(), 404);

        parent::mount($brand);
    }

    protected function place(): string
    {
        return 'livechat';
    }
}
