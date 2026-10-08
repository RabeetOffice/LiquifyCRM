<?php

declare(strict_types=1);

namespace App\Modules\Brands\Support;

use App\Modules\Brands\Models\Brand;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Sends through a brand's own SMTP account (the encrypted mail_settings),
 * so customers get replies from the brand, not from LiquifyCRM.
 * Not final so tests can replace it.
 */
class BrandMailer
{
    public function send(Brand $brand, string $to, string $subject, string $body): void
    {
        $settings = $brand->mailSettings();

        if (! $brand->hasMailSettings()) {
            throw new RuntimeException('Email settings are not filled in for '.$brand->name.'.');
        }

        $this->mailer($brand)->raw($body, function (Message $message) use ($settings, $brand, $to, $subject): void {
            $message->to($to)
                ->subject($subject)
                ->from($settings['from_address'], $settings['from_name'] ?: $brand->name);
        });
    }

    protected function mailer(Brand $brand): Mailer
    {
        $settings = $brand->mailSettings();

        return Mail::build([
            'transport' => 'smtp',
            'host' => $settings['host'],
            'port' => $settings['port'],
            'encryption' => $settings['encryption'] === 'none' ? null : $settings['encryption'],
            'scheme' => $settings['encryption'] === 'ssl' ? 'smtps' : 'smtp',
            'username' => $settings['username'] ?: null,
            'password' => $settings['password'] ?: null,
            'timeout' => 15,
        ]);
    }

    /** "Gmail" or "Outlook" when the SMTP host is one of theirs. */
    public static function provider(?string $host): ?string
    {
        $host = mb_strtolower((string) $host);

        return match (true) {
            str_contains($host, 'gmail') || str_contains($host, 'google') => 'Gmail',
            str_contains($host, 'outlook') || str_contains($host, 'office365') || str_contains($host, 'hotmail') => 'Outlook',
            default => null,
        };
    }
}
