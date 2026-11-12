<?php

declare(strict_types=1);

namespace App\Modules\Chat\Http\Controllers;

use App\Modules\Brands\Models\Brand;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Support\LiveChat;
use App\Modules\Core\Support\Audit;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Download liquify-chat.php for one chat box, with LiquifyCRM's address, the box's key and its secret filled in: the file
 * that serves the chat from the brand's own website. For whoever may change the chat box.
 */
final class ChatProxyFileController
{
    public function __invoke(Brand $brand, int $widget): Response
    {
        abort_unless(LiveChat::proxyReady(), 404);

        $box = ChatWidget::query()->where('brand_id', $brand->getKey())->findOrFail($widget);
        Gate::authorize('update', $box);

        Audit::log('chat.proxy_file_downloaded', $box, ['name' => $box->name], 'liquify-chat.php downloaded for '.$box->name, brand: $brand, logName: 'chat');

        return response($box->proxyFile(), 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="liquify-chat.php"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
