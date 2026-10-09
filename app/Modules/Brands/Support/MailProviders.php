<?php

declare(strict_types=1);

namespace App\Modules\Brands\Support;

/**
 * Ready-made outgoing mail (SMTP) settings for the email services brands
 * usually send from. Choosing one fills in the server, port and security and
 * shows what that service asks for (an app password, a switch to turn on).
 * Every value stays editable; "other" is for anything not listed.
 *
 * Each service shows its own icon, fetched by the browser from Google's
 * favicon service (asked for by the user on 9 October); the coloured letter
 * tile underneath is what shows if that image cannot be loaded.
 *
 * @phpstan-type Provider array{label: string, group: string, host: string, port: int, encryption: string, color: string, mark: string, domain?: string, username?: string, match: list<string>, note: string}
 */
final class MailProviders
{
    public const OTHER = 'other';

    /** "mail." in front of the brand's own domain (cPanel and most hosting webmail). */
    public const WEBMAIL = 'webmail';

    /** @return array<string, Provider> */
    public static function all(): array
    {
        return [
            'google' => [
                'label' => 'Google (Gmail)', 'group' => 'Mailboxes', 'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls',
                'color' => '#EA4335', 'mark' => 'G', 'domain' => 'gmail.com', 'match' => ['gmail', 'google'],
                'note' => 'Gmail and Google Workspace need a 16-character app password, not your normal password. Turn on 2-Step Verification, then create one under Google Account > Security > App passwords. The username is the full email address.',
            ],
            'microsoft' => [
                'label' => 'Microsoft 365 / Exchange', 'group' => 'Mailboxes', 'host' => 'smtp.office365.com', 'port' => 587, 'encryption' => 'tls',
                'color' => '#0078D4', 'mark' => 'M', 'domain' => 'microsoft.com', 'match' => ['office365', 'outlook', 'hotmail', 'live.com'],
                'note' => 'The mailbox needs "Authenticated SMTP" switched on (Microsoft 365 admin centre > Users > the mailbox > Mail > Manage email apps). Microsoft has been switching off password sign-in for sending mail, so if the test email is refused, ask your Microsoft 365 admin or use a sending service. For your own Exchange server, replace the server with its address.',
            ],
            'hostinger' => [
                'label' => 'Hostinger Email', 'group' => 'Mailboxes', 'host' => 'smtp.hostinger.com', 'port' => 465, 'encryption' => 'ssl',
                'color' => '#673DE6', 'mark' => 'H', 'domain' => 'hostinger.com', 'match' => ['hostinger'],
                'note' => 'Use the mailbox you created in hPanel > Emails: its full address as the username and its own password.',
            ],
            'titan' => [
                'label' => 'Titan Email', 'group' => 'Mailboxes', 'host' => 'smtp.titan.email', 'port' => 465, 'encryption' => 'ssl',
                'color' => '#F2613F', 'mark' => 'T', 'domain' => 'titan.email', 'match' => ['titan.email'],
                'note' => 'Use the full Titan address and its password. If sending is refused, allow third-party apps for the mailbox in the Titan settings.',
            ],
            self::WEBMAIL => [
                'label' => 'Webmail (cPanel)', 'group' => 'Mailboxes', 'host' => '', 'port' => 465, 'encryption' => 'ssl',
                'color' => '#FF6C2C', 'mark' => 'W', 'domain' => 'cpanel.net', 'match' => [],
                'note' => 'For a mailbox made in your hosting panel (cPanel > Email Accounts). The server is usually "mail." followed by your domain; the username is the full email address.',
            ],
            'zoho' => [
                'label' => 'Zoho Mail', 'group' => 'Mailboxes', 'host' => 'smtp.zoho.com', 'port' => 465, 'encryption' => 'ssl',
                'color' => '#E42527', 'mark' => 'Z', 'domain' => 'zoho.com', 'match' => ['zoho'],
                'note' => 'Accounts hosted in Europe use smtp.zoho.eu, and paid organisation accounts use smtppro.zoho.com. With two-factor sign-in on, create an app-specific password in Zoho Accounts > Security.',
            ],
            'yahoo' => [
                'label' => 'Yahoo Mail', 'group' => 'Mailboxes', 'host' => 'smtp.mail.yahoo.com', 'port' => 465, 'encryption' => 'ssl',
                'color' => '#6001D2', 'mark' => 'Y', 'domain' => 'yahoo.com', 'match' => ['yahoo'],
                'note' => 'Yahoo needs an app password: Account security > Generate app password. Your normal password will not work.',
            ],
            'godaddy' => [
                'label' => 'GoDaddy Email', 'group' => 'Mailboxes', 'host' => 'smtpout.secureserver.net', 'port' => 465, 'encryption' => 'ssl',
                'color' => '#1BDBDB', 'mark' => 'GD', 'domain' => 'godaddy.com', 'match' => ['secureserver'],
                'note' => 'For GoDaddy Workspace Email. If your GoDaddy mailbox is a Microsoft 365 one, choose Microsoft 365 instead.',
            ],
            'namecheap' => [
                'label' => 'Namecheap Private Email', 'group' => 'Mailboxes', 'host' => 'mail.privateemail.com', 'port' => 465, 'encryption' => 'ssl',
                'color' => '#DE3723', 'mark' => 'N', 'domain' => 'namecheap.com', 'match' => ['privateemail'],
                'note' => 'Use the full Private Email address and its password.',
            ],
            'icloud' => [
                'label' => 'iCloud Mail', 'group' => 'Mailboxes', 'host' => 'smtp.mail.me.com', 'port' => 587, 'encryption' => 'tls',
                'color' => '#3693F3', 'mark' => 'iC', 'domain' => 'icloud.com', 'match' => ['mail.me.com', 'icloud'],
                'note' => 'iCloud needs an app-specific password, created at appleid.apple.com > Sign-In and Security.',
            ],
            'sendgrid' => [
                'label' => 'SendGrid', 'group' => 'Sending services', 'host' => 'smtp.sendgrid.net', 'port' => 587, 'encryption' => 'tls',
                'color' => '#1A82E2', 'mark' => 'SG', 'domain' => 'sendgrid.com', 'username' => 'apikey', 'match' => ['sendgrid'],
                'note' => 'The username is the word "apikey" and the password is an API key with Mail Send access. The From address must be a verified sender.',
            ],
            'brevo' => [
                'label' => 'Brevo', 'group' => 'Sending services', 'host' => 'smtp-relay.brevo.com', 'port' => 587, 'encryption' => 'tls',
                'color' => '#0B996E', 'mark' => 'B', 'domain' => 'brevo.com', 'match' => ['brevo', 'sendinblue'],
                'note' => 'Use the SMTP login and the SMTP key from Brevo > SMTP and API (not your account password). The From address must be a verified sender.',
            ],
            'mailgun' => [
                'label' => 'Mailgun', 'group' => 'Sending services', 'host' => 'smtp.mailgun.org', 'port' => 587, 'encryption' => 'tls',
                'color' => '#F06B66', 'mark' => 'MG', 'domain' => 'mailgun.com', 'match' => ['mailgun'],
                'note' => 'Use the SMTP credentials of your sending domain (Mailgun > Sending > Domain settings). Domains in the EU region use smtp.eu.mailgun.org.',
            ],
            self::OTHER => [
                'label' => 'Another provider', 'group' => 'Other', 'host' => '', 'port' => 587, 'encryption' => 'tls',
                'color' => '#78716C', 'mark' => '…', 'match' => [],
                'note' => 'Ask your email provider for its outgoing mail (SMTP) server, port and security, and enter them below. Port 587 goes with TLS and port 465 with SSL.',
            ],
        ];
    }

    /** The service's icon, or null for "another provider". */
    public static function iconUrl(string $key): ?string
    {
        $domain = self::all()[$key]['domain'] ?? null;

        return $domain !== null ? 'https://www.google.com/s2/favicons?domain='.urlencode($domain).'&sz=64' : null;
    }

    /** @return Provider|null */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** Which provider a server address belongs to; null when no server is entered. */
    public static function detect(?string $host): ?string
    {
        $host = mb_strtolower(trim((string) $host));

        if ($host === '') {
            return null;
        }

        foreach (self::all() as $key => $provider) {
            foreach ($provider['match'] as $needle) {
                if (str_contains($host, $needle)) {
                    return $key;
                }
            }
        }

        return str_starts_with($host, 'mail.') ? self::WEBMAIL : self::OTHER;
    }

    /** "mail.ukpublishinghouse.co.uk" from the brand's website, for the webmail preset. */
    public static function webmailHost(?string $website): string
    {
        $host = mb_strtolower((string) parse_url((string) $website, PHP_URL_HOST));
        $host = str_starts_with($host, 'www.') ? mb_substr($host, 4) : $host;

        return $host !== '' ? 'mail.'.$host : '';
    }

    /**
     * The providers under their group headings, for the picker.
     *
     * @return array<string, array<string, Provider>>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::all() as $key => $provider) {
            $groups[$provider['group']][$key] = $provider;
        }

        return $groups;
    }
}
