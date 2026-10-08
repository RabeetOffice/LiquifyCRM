<?php

declare(strict_types=1);

namespace App\Modules\Meta\Support;

/**
 * The Meta app LiquifyCRM talks through. Its id, secret and verify token
 * live only in .env (config/liquify.php, "meta").
 */
final class MetaApp
{
    /** Permissions asked for at "Connect Facebook", and what each is for. */
    public const SCOPES = [
        'pages_show_list' => 'list your Pages',
        'pages_manage_metadata' => 'subscribe each Page to leads and messages',
        'pages_read_engagement' => 'read Page details',
        'leads_retrieval' => 'read Lead Ads leads',
        'pages_messaging' => 'receive and answer Messenger chats',
        'business_management' => 'see the Pages of your Business Manager',
    ];

    public static function id(): string
    {
        return (string) config('liquify.meta.app_id');
    }

    public static function secret(): string
    {
        return (string) config('liquify.meta.app_secret');
    }

    public static function verifyToken(): string
    {
        return (string) config('liquify.meta.verify_token');
    }

    public static function version(): string
    {
        return (string) config('liquify.meta.graph_version', 'v26.0');
    }

    public static function isConfigured(): bool
    {
        return self::id() !== '' && self::secret() !== '';
    }

    public static function webhookUrl(): string
    {
        return url('/webhooks/meta');
    }

    public static function redirectUri(): string
    {
        return url('/integrations/facebook/callback');
    }

    /** The Facebook Login dialog; with a Login for Business configuration id the scopes come from that configuration. */
    public static function loginUrl(string $state): string
    {
        $configId = (string) config('liquify.meta.login_config_id');

        return 'https://www.facebook.com/'.self::version().'/dialog/oauth?'.http_build_query(array_filter([
            'client_id' => self::id(),
            'redirect_uri' => self::redirectUri(),
            'state' => $state,
            'response_type' => 'code',
            'config_id' => $configId !== '' ? $configId : null,
            'scope' => $configId === '' ? implode(',', array_keys(self::SCOPES)) : null,
        ]));
    }

    /** X-Hub-Signature-256: "sha256=" + HMAC-SHA256 of the raw body with the app secret. */
    public static function signatureMatches(string $body, string $header): bool
    {
        if (self::secret() === '' || $header === '') {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $body, self::secret()), $header);
    }
}
