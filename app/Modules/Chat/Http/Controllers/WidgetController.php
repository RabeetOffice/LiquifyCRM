<?php

declare(strict_types=1);

namespace App\Modules\Chat\Http\Controllers;

use App\Modules\Auth\Support\Device;
use App\Modules\Brands\Models\Brand;
use App\Modules\Chat\Actions\CaptureVisitorDetails;
use App\Modules\Chat\Actions\RecordVisit;
use App\Modules\Chat\Actions\SendVisitorMessage;
use App\Modules\Chat\Models\ChatVisitor;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Support\AgentPresence;
use App\Modules\Chat\Support\ChatFiles;
use App\Modules\Chat\Support\ChatTyping;
use App\Modules\Chat\Support\LiveChat;
use App\Modules\Chat\Support\VisitorChats;
use App\Modules\Chat\Support\VisitorToken;
use App\Modules\Core\Support\CurrentBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * What the chat box on a website talks to. No session and no login: a request names the widget (its key) and
 * proves who the visitor is with the token and signature the chat box was given (VisitorToken). Everything
 * a visitor can do is here: open, read the messages, write, and give their details.
 */
final class WidgetController
{
    /**
     * The launcher on the website asks this first, from the website itself: only a website the widget is set
     * up for gets an answer (and the browser's permission to read it), so the chat box appears nowhere else.
     */
    public function config(Request $request): JsonResponse
    {
        $widget = $this->widget((string) $request->query('key'));
        $origin = (string) ($request->headers->get('Origin') ?? $request->query('o', ''));

        if ($widget === null || ! $widget->allowsOrigin($origin)) {
            return response()->json(['active' => false], 404);
        }

        return response()->json([
            'active' => true,
            'color' => $widget->look('color'),
            'on' => $widget->onColor(),
            'position' => $widget->look('position'),
            'title' => $widget->look('title'),
            'frame' => url('/chat/frame/'.$widget->widget_key),
        ], 200, [
            'Access-Control-Allow-Origin' => $origin,
            'Vary' => 'Origin',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /** The chat box opens: the visitor's token (a new one when they have none), what to show, and the chat so far. */
    public function init(Request $request, RecordVisit $visit): JsonResponse
    {
        $widget = $this->widget((string) $request->header('X-Chat-Key'));
        abort_if($widget === null, 404);
        $this->enter($widget);

        $device = Device::of($request);
        [$os, $browser] = array_pad(explode(' · ', $device->name, 2), 2, null);

        $visitor = $visit->handle($widget, $request->header('X-Chat-Token'), $request->header('X-Chat-Sig'), [
            'ip' => $request->ip(),
            'country' => self::country($request),
            'kind' => $device->kind->value,
            'os' => $os,
            'browser' => $browser,
        ]);
        abort_if($visitor->is_banned, 403);

        $conversation = VisitorChats::conversationFor($visitor, $widget, false);

        return response()->json([
            'token' => $visitor->visitor_token,
            'sig' => VisitorToken::sign($visitor->visitor_token),
            'config' => $this->settings($widget),
            'visitor' => ['name' => $visitor->name, 'has_details' => $visitor->lead_id !== null || filled($visitor->getRawOriginal('email')) || filled($visitor->getRawOriginal('phone'))],
            'messages' => $conversation !== null ? VisitorChats::messages($conversation) : [],
            'closed' => $conversation?->isClosed() ?? false,
        ]);
    }

    /** The chat box asks for what is new since the last message it has. */
    public function messages(Request $request): JsonResponse
    {
        [$widget, $visitor] = $this->visitor($request);

        $conversation = VisitorChats::conversationFor($visitor, $widget, false);
        $visitor->forceFill(['last_seen_at' => now()])->saveQuietly();

        return response()->json([
            'messages' => $conversation !== null ? VisitorChats::messages($conversation, (int) $request->query('after', 0)) : [],
            'agent_typing' => $conversation !== null && ChatTyping::agentIsTyping((int) $conversation->getKey()),
            'closed' => $conversation?->isClosed() ?? false,
            'online' => AgentPresence::online((int) $widget->brand_id),
        ]);
    }

    /** A file the visitor sends: a picture, a PDF, a Word document or a text file, with or without words. */
    public function upload(Request $request, SendVisitorMessage $send): JsonResponse
    {
        [$widget, $visitor] = $this->visitor($request);
        abort_unless(LiveChat::extrasReady(), 404);

        $request->validate(['file' => ['required', 'file'], 'caption' => ['nullable', 'string', 'max:2000']]);

        try {
            $sent = $send->handle($widget, $visitor, (string) $request->input('caption', ''), detect: false, file: $request->file('file'));
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => VisitorChats::row($sent['message']->load('chatFiles'))]);
    }

    /** The visitor is typing: the team's open chat shows the dots for a few seconds. */
    public function typing(Request $request): JsonResponse
    {
        [$widget, $visitor] = $this->visitor($request);
        $conversation = VisitorChats::conversationFor($visitor, $widget, false);

        if ($conversation !== null) {
            ChatTyping::visitorTypes((int) $widget->brand_id, (int) $conversation->getKey());
        }

        return response()->json(['ok' => true]);
    }

    public function send(Request $request, SendVisitorMessage $send): JsonResponse
    {
        [$widget, $visitor] = $this->visitor($request);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        try {
            $sent = $send->handle($widget, $visitor, $data['body']);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => VisitorChats::row($sent['message']), 'suggest' => $sent['suggest']]);
    }

    /** The pre-chat form, the details card, or a yes to "is this the best way to reach you?". */
    public function details(Request $request, CaptureVisitorDetails $capture, SendVisitorMessage $send): JsonResponse
    {
        [$widget, $visitor] = $this->visitor($request);

        // A field real people never see: a robot that fills it in gets a quiet "thank you" and nothing is kept.
        if (filled($request->input('website'))) {
            return response()->json(['ok' => true, 'lead' => false]);
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:60'],
            'message' => ['nullable', 'string', 'max:5000'],
            'page' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $capture->handle($widget, $visitor, $data, $data['message'] ?? null, $data['page'] ?? null);

        if ($result['errors'] !== []) {
            return response()->json(['ok' => false, 'errors' => $result['errors']], 422);
        }

        // The pre-chat form's own question is the first message of the chat.
        $message = null;

        if (filled($data['message'] ?? null)) {
            try {
                $message = VisitorChats::row($send->handle($widget, $visitor->refresh(), (string) $data['message'], detect: false)['message']);
            } catch (RuntimeException $exception) {
                return response()->json(['ok' => false, 'errors' => ['message' => $exception->getMessage()]], 422);
            }
        }

        return response()->json(['ok' => true, 'lead' => $result['lead'] !== null, 'name' => $visitor->refresh()->name, 'message' => $message]);
    }

    /** @return array<string, mixed> */
    private function settings(ChatWidget $widget): array
    {
        return [
            'title' => $widget->look('title'),
            'welcome' => $widget->look('welcome'),
            'consent' => $widget->look('consent'),
            'offline' => $widget->look('offline'),
            'online' => AgentPresence::online((int) $widget->brand_id),
            'files' => LiveChat::extrasReady() ? ['max_mb' => (int) (ChatFiles::maxBytes($widget) / 1048576), 'accept' => ChatFiles::accept()] : null,
            'prechat' => ['enabled' => $widget->asksFirst(), 'fields' => collect(ChatWidget::PRECHAT_FIELDS)->map(fn (string $label, string $field): bool => $widget->asks($field))->all(), 'labels' => ChatWidget::PRECHAT_FIELDS],
        ];
    }

    /** The widget of this key, when it is switched on. */
    private function widget(string $key): ?ChatWidget
    {
        if (! LiveChat::ready() || preg_match('/^wk_[A-Za-z0-9]{24}$/', $key) !== 1) {
            return null;
        }

        return ChatWidget::query()->withoutGlobalScopes()->where('widget_key', $key)->where('is_active', true)->first();
    }

    /** Queries from here on belong to the widget's brand, as in the rest of the app. */
    private function enter(ChatWidget $widget): void
    {
        app(CurrentBrand::class)->set(Brand::query()->findOrFail($widget->brand_id));
    }

    /** @return array{0: ChatWidget, 1: ChatVisitor} */
    private function visitor(Request $request): array
    {
        $widget = $this->widget((string) $request->header('X-Chat-Key'));
        abort_if($widget === null, 404);

        $token = $request->header('X-Chat-Token');
        abort_unless(VisitorToken::valid($token, $request->header('X-Chat-Sig')), 401);

        $this->enter($widget);

        $visitor = ChatVisitor::query()->where('brand_id', $widget->brand_id)->where('visitor_token', (string) $token)->first();
        abort_if($visitor === null, 401);
        abort_if($visitor->is_banned, 403);

        return [$widget, $visitor];
    }

    /** The country the CDN saw, when it says (two letters). */
    private static function country(Request $request): ?string
    {
        foreach (['CF-IPCountry', 'X-Country-Code'] as $header) {
            $value = strtoupper(trim((string) $request->header($header)));

            if (preg_match('/^[A-Z]{2}$/', $value) === 1 && $value !== 'XX') {
                return $value;
            }
        }

        return null;
    }
}
