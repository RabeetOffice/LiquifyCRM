<?php

declare(strict_types=1);

namespace App\Modules\Chat\Http\Controllers;

use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Support\LiveChat;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The two pieces a website loads: the small launcher script (`widget.js`, the one line the website carries)
 * and the chat box itself, a page shown in an iframe so the website's own styles and scripts cannot reach it.
 * No session and no cookies on either.
 */
final class WidgetAssetController
{
    /** The launcher. An hour in the browser's cache: a change reaches websites within the hour. */
    public function script(): Response
    {
        $source = (string) file_get_contents(app_path('Modules/Chat/resources/widget/widget.js'));

        return response($source, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The chat box. Only the websites the widget is set up for may show it in a frame: the browser enforces
     * that (`frame-ancestors`), and the launcher's own request already refused any other website.
     */
    public function frame(Request $request, string $key): Response
    {
        $widget = LiveChat::ready() && preg_match('/^wk_[A-Za-z0-9]{24}$/', $key) === 1
            ? ChatWidget::query()->withoutGlobalScopes()->where('widget_key', $key)->where('is_active', true)->first()
            : null;

        $origin = (string) $request->query('o', '');

        abort_if($widget === null || ! $widget->allowsOrigin($origin), 404);

        $ancestors = collect($widget->allowed_domains ?? [])->flatMap(fn (string $domain): array => ['https://'.$domain, 'https://*.'.$domain]);

        // A local copy of the website runs on http and a port.
        if (app()->environment('local')) {
            $ancestors = $ancestors->merge(['http://localhost:*', 'http://127.0.0.1:*']);
        }

        return response()->view('chat::frame', [
            'key' => $widget->widget_key,
            'origin' => $origin,
            'color' => $widget->look('color'),
            'on' => $widget->onColor(),
            'title' => $widget->look('title'),
        ], 200, [
            'Content-Security-Policy' => 'frame-ancestors '.$ancestors->implode(' '),
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
