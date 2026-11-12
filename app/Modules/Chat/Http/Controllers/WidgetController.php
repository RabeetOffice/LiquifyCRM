<?php

declare(strict_types=1);

namespace App\Modules\Chat\Http\Controllers;

use App\Modules\Auth\Support\Device;
use App\Modules\Brands\Models\Brand;
use App\Modules\Chat\Actions\CaptureVisitorDetails;
use App\Modules\Chat\Actions\RateChat;
use App\Modules\Chat\Actions\RecordPing;
use App\Modules\Chat\Actions\RecordVisit;
use App\Modules\Chat\Actions\SendVisitorMessage;
use App\Modules\Chat\Models\ChatSession;
use App\Modules\Chat\Models\ChatVisitor;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Support\AgentCard;
use App\Modules\Chat\Support\AgentPresence;
use App\Modules\Chat\Support\ChatFiles;
use App\Modules\Chat\Support\ChatProxy;
use App\Modules\Chat\Support\ChatTyping;
use App\Modules\Chat\Support\LiveChat;
use App\Modules\Chat\Support\Realtime;
use App\Modules\Chat\Support\VisitorChats;
use App\Modules\Chat\Support\VisitorToken;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Meta\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * What the chat box on a website talks to. No session and no login: a request names the widget (its key) and
 * proves who the visitor is with the token and signature the chat box was given (VisitorToken). Everything
 * a visitor can do is here: open, read the messages, write, and give their details.
 */
final class WidgetController
{
    /** The code the launcher keeps in the website's own storage for a browser: a UUID. */
    private const VID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** New visitors (browsers without a token) one address may start in a minute. */
    public const NEW_VISITORS_PER_MINUTE = 10;

    /** New browsers on the website (Live visitors) one address may report in a minute. */
    public const NEW_SESSIONS_PER_MINUTE = 20;

    /**
     * The launcher on the website asks this first, from the website itself: only a website the widget is set
     * up for gets an answer (and the browser's permission to read it), so the chat box appears nowhere else.
     */
    public function config(Request $request): JsonResponse
    {
        $widget = $this->widget((string) $request->query('key'));
        $origin = $widget?->originFor((string) ($request->headers->get('Origin') ?? $request->query('o', '')));

        if ($widget === null || $origin === null) {
            return response()->json(['active' => false], 404);
        }

        return response()->json([
            'active' => true,
            ...$widget->design(),
            'title' => $widget->look('title'),
            'welcome' => $widget->look('welcome'),
            // Whether somebody of the team is here: the launcher's line under its words says so.
            'online' => AgentPresence::online((int) $widget->brand_id),
            // Whether the launcher tells the server which page it is on (Live visitors).
            'track' => LiveChat::trackingReady() && $widget->tracks(),
            'frame' => ChatProxy::url($request, url('/chat/frame/'.$widget->widget_key)),
        ], 200, [
            'Access-Control-Allow-Origin' => $origin,
            'Vary' => 'Origin',
            'Cache-Control' => 'public, max-age=60',
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

        // The browser the launcher reports (its "vid") may already have a chat: one the team started with an invitation takes
        // the place of the token this chat box held; otherwise the browser is tied to this visitor from now on.
        $vid = (string) $request->input('vid');
        $token = $request->header('X-Chat-Token');
        $sig = $request->header('X-Chat-Sig');
        $session = LiveChat::trackingReady() && preg_match(self::VID, $vid) === 1
            ? ChatSession::query()->where('brand_id', $widget->brand_id)->where('vid', $vid)->first()
            : null;
        $invited = $session?->chat_visitor_id !== null ? ChatVisitor::query()->find($session->chat_visitor_id) : null;

        if ($invited !== null && ! $invited->is_banned && $token !== $invited->visitor_token) {
            $token = $invited->visitor_token;
            $sig = VisitorToken::sign($token);
        }

        // A browser without a token gets a new visitor: a script asking again and again would fill the table, so an address
        // gets a few new visitors a minute (an office of real people opens the chat far less often than that).
        if (! VisitorToken::valid($token, $sig)) {
            $newKey = 'chat-new-visitor:'.ChatProxy::ip($request);
            abort_if(RateLimiter::tooManyAttempts($newKey, self::NEW_VISITORS_PER_MINUTE), 429);
            RateLimiter::hit($newKey, 60);
        }

        $visitor = $visit->handle($widget, $token, $sig, [
            'ip' => ChatProxy::ip($request),
            'country' => self::country($request),
            'kind' => $device->kind->value,
            'os' => $os,
            'browser' => $browser,
        ]);
        abort_if($visitor->is_banned, 403);

        if ($session !== null && $session->chat_visitor_id === null) {
            $session->forceFill(['chat_visitor_id' => $visitor->getKey()])->save();
        }

        $conversation = VisitorChats::conversationFor($visitor, $widget, false);

        return response()->json([
            'token' => $visitor->visitor_token,
            'sig' => VisitorToken::sign($visitor->visitor_token),
            'config' => $this->settings($widget),
            'visitor' => ['name' => $visitor->name, 'has_details' => $visitor->lead_id !== null || filled($visitor->getRawOriginal('email')) || filled($visitor->getRawOriginal('phone'))],
            'messages' => $conversation !== null ? VisitorChats::messages($conversation) : [],
            'closed' => $conversation?->isClosed() ?? false,
            'rating' => LiveChat::ratingReady() ? $conversation?->getAttribute('rating') : null,
            // Who looks after this chat, for the chat box's header (name, title, photo).
            'agent' => self::agentCard($conversation, null),
        ]);
    }

    /**
     * The person looking after the chat as the chat box shows them; the photo only when the chat box does not have it yet
     * (it says which person it shows), so it is not sent with every ask.
     *
     * @return array{id: int, name: string, title: ?string, photo: ?string}|null
     */
    private static function agentCard(?Conversation $conversation, ?int $known): ?array
    {
        $agent = $conversation !== null ? AgentCard::agentOf($conversation) : null;

        return $agent !== null ? AgentCard::of($agent, withPhoto: $known !== (int) $agent->getKey()) : null;
    }

    /**
     * The launcher on the website, every half minute: this browser is here, on this page. Answered only to a website the
     * widget is set up for (the browser's Origin), and nothing is kept when the brand switched visitors off. The body is
     * plain text holding JSON, so the browser sends it without asking first. What comes back is an invitation, once.
     */
    public function ping(Request $request, RecordPing $record): Response|JsonResponse
    {
        $data = json_decode((string) $request->getContent(), true);
        $data = is_array($data) ? $data : [];
        $widget = $this->widget((string) ($data['key'] ?? ''));
        $origin = $widget?->originFor((string) $request->headers->get('Origin'));
        $vid = (string) ($data['vid'] ?? '');

        if ($widget === null || $origin === null || ! LiveChat::trackingReady() || preg_match(self::VID, $vid) !== 1) {
            return response()->noContent(204);
        }

        $headers = ['Access-Control-Allow-Origin' => $origin, 'Vary' => 'Origin', 'Cache-Control' => 'no-store'];

        // Crawlers, page testers and scripts are not visitors: Live visitors shows people.
        if (! $widget->tracks() || self::isBot((string) $request->userAgent())) {
            return response()->noContent(204, $headers);
        }

        $this->enter($widget);
        $device = Device::of($request);

        $invite = $record->handle($widget, $vid, ['url' => $data['url'] ?? null, 'title' => $data['title'] ?? null, 'referrer' => $data['referrer'] ?? null], [
            'new_key' => 'chat-new-session:'.ChatProxy::ip($request),
            'tz' => is_string($data['tz'] ?? null) ? $data['tz'] : null,
            'ip' => ChatProxy::ip($request),
            'country' => self::country($request),
            'device' => $device->kind->value,
            'browser' => array_pad(explode(' · ', $device->name, 2), 2, null)[1],
        ]);

        return response()->json(['invite' => $invite], 200, $headers);
    }

    /** The visitor gives the chat a thumb up or down. */
    public function rate(Request $request, RateChat $rate): JsonResponse
    {
        [$widget, $visitor] = $this->visitor($request);
        $data = $request->validate(['rating' => ['required', 'in:up,down']]);

        try {
            $rate->handle($widget, $visitor, $data['rating']);
        } catch (RuntimeException $exception) {
            return response()->json(['ok' => false, 'error' => $exception->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'rating' => $data['rating']]);
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
            'agent' => self::agentCard($conversation, $request->integer('agent') ?: null),
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

    /** The address the chat box opens to hear "something changed" the moment it happens (empty when that is not switched on). */
    public function realtime(Request $request): JsonResponse
    {
        [, $visitor] = $this->visitor($request);

        $url = Realtime::enabled() ? Realtime::listenUrl(Realtime::visitorChannel($visitor->visitor_token)) : null;

        return response()->json($url === null ? ['enabled' => false] : ['enabled' => true, 'url' => $url, 'refresh_in' => Realtime::refreshInSeconds()])->header('Cache-Control', 'no-store');
    }

    /** The visitor is typing: the team's open chat shows the dots for a few seconds. */
    public function typing(Request $request): JsonResponse
    {
        [$widget, $visitor] = $this->visitor($request);
        $conversation = VisitorChats::conversationFor($visitor, $widget, false);

        if ($conversation !== null) {
            $request->boolean('stop')
                ? ChatTyping::visitorStops((int) $widget->brand_id, (int) $conversation->getKey())
                : ChatTyping::visitorTypes((int) $widget->brand_id, (int) $conversation->getKey());
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
            'rating' => LiveChat::ratingReady() && $widget->look('rating') !== '0',
            'realtime' => Realtime::enabled(),
            'track' => LiveChat::trackingReady() && $widget->tracks(),
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
    /** A crawler, a page tester or a script rather than a person in a browser. */
    public static function isBot(string $agent): bool
    {
        return $agent === '' || preg_match('/bot|crawl|spider|slurp|headless|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|facebookexternalhit|whatsapp|telegram|curl|wget|python|scrapy|axios|node-fetch|okhttp|go-http|java\/|phantom|puppeteer|playwright|selenium|screenshot|prerender/i', $agent) === 1;
    }

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
