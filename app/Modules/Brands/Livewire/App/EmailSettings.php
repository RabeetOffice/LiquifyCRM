<?php

declare(strict_types=1);

namespace App\Modules\Brands\Livewire\App;

use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Support\BrandMailer;
use App\Modules\Brands\Support\MailProviders;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * The brand's own email account (SMTP), stored encrypted, and the optional
 * auto-reply a customer gets after sending a website form. A picker of
 * ready-made providers (Google, Microsoft 365, Hostinger, webmail and more)
 * fills in the server details.
 */
#[Layout('components.layouts.app')]
#[Title('Email settings')]
final class EmailSettings extends Component
{
    use AuthorizesRequests;
    use WithToasts;

    public Brand $brand;

    /** The provider chosen in the picker; empty until one is chosen or a server is saved. */
    public string $preset = '';

    public string $host = '';

    public string $port = '587';

    public string $encryption = 'tls';

    public string $username = '';

    public string $password = '';

    public string $fromAddress = '';

    public string $fromName = '';

    public bool $autoReply = false;

    public string $autoReplySubject = '';

    public string $autoReplyBody = '';

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorize('update', $brand);

        $settings = $brand->mailSettings();

        $this->host = $settings['host'];
        $this->port = (string) $settings['port'];
        $this->encryption = $settings['encryption'];
        $this->username = $settings['username'];
        $this->fromAddress = $settings['from_address'];
        $this->fromName = $settings['from_name'] ?: $brand->name;
        $this->autoReply = $settings['auto_reply'];
        $this->autoReplySubject = $settings['auto_reply_subject'];
        $this->autoReplyBody = $settings['auto_reply_body'];
        $this->preset = (string) MailProviders::detect($this->host);
    }

    /**
     * Choose a ready-made provider: its server, port and security are filled
     * in. The username, password and From address are never touched, except
     * where the service fixes the username (SendGrid's "apikey").
     */
    public function usePreset(string $key): void
    {
        $provider = MailProviders::find($key);

        if ($provider === null) {
            return;
        }

        $this->preset = $key;
        $this->resetErrorBag(['host', 'port', 'encryption']);

        if ($key === MailProviders::OTHER) {
            return;
        }

        $this->host = $key === MailProviders::WEBMAIL ? MailProviders::webmailHost($this->brand->website) : $provider['host'];
        $this->port = (string) $provider['port'];
        $this->encryption = $provider['encryption'];

        if (isset($provider['username']) && $this->username === '') {
            $this->username = $provider['username'];
        }
    }

    /** Typing a server by hand moves the picker to the provider it belongs to. */
    public function updatedHost(string $value): void
    {
        $detected = MailProviders::detect($value);

        if ($detected !== null && ! ($detected === MailProviders::OTHER && $this->preset === MailProviders::WEBMAIL)) {
            $this->preset = $detected;
        }
    }

    /** SSL goes with port 465 and TLS with 587: keep the pair together unless a custom port was typed. */
    public function updatedEncryption(string $value): void
    {
        if ($value === 'ssl' && in_array($this->port, ['587', '25', ''], true)) {
            $this->port = '465';
        } elseif ($value === 'tls' && in_array($this->port, ['465', '25', ''], true)) {
            $this->port = '587';
        }
    }

    public function save(): void
    {
        $this->authorize('update', $this->brand);

        $data = $this->validate([
            'host' => [$this->autoReply ? 'required' : 'nullable', 'string', 'max:190'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
            'username' => ['nullable', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'max:255'],
            'fromAddress' => [$this->autoReply ? 'required' : 'nullable', 'email', 'max:190'],
            'fromName' => ['nullable', 'string', 'max:120'],
            'autoReply' => ['boolean'],
            'autoReplySubject' => [$this->autoReply ? 'required' : 'nullable', 'string', 'max:190'],
            'autoReplyBody' => [$this->autoReply ? 'required' : 'nullable', 'string', 'max:5000'],
        ], attributes: ['host' => 'server', 'fromAddress' => 'from address', 'autoReplySubject' => 'subject', 'autoReplyBody' => 'message']);

        $this->brand->update(['mail_settings' => [
            'host' => trim((string) $data['host']),
            'port' => (int) $data['port'],
            'encryption' => $data['encryption'],
            'username' => trim((string) $data['username']),
            // An empty password field keeps the stored one.
            'password' => filled($data['password']) ? $data['password'] : $this->brand->mailSettings()['password'],
            'from_address' => mb_strtolower(trim((string) $data['fromAddress'])),
            'from_name' => trim((string) $data['fromName']),
            'auto_reply' => (bool) $data['autoReply'],
            'auto_reply_subject' => (string) $data['autoReplySubject'],
            'auto_reply_body' => (string) $data['autoReplyBody'],
        ]]);

        $this->password = '';

        Audit::log('brands.mail_settings_updated', $this->brand, ['host' => $data['host'], 'auto_reply' => (bool) $data['autoReply']], 'Email settings saved', brand: $this->brand, logName: 'brands');

        $this->toastSuccess('Email settings saved');
    }

    public function sendTest(BrandMailer $mailer): void
    {
        $this->authorize('update', $this->brand);
        $this->brand->refresh();

        $to = (string) auth()->user()?->email;

        try {
            $mailer->send($this->brand, $to, 'Test email from '.$this->brand->name, "This is a test from LiquifyCRM.\n\nThe email settings of ".$this->brand->name.' work.');
        } catch (Throwable $exception) {
            $this->toastDanger('The test email could not be sent', mb_substr($exception->getMessage(), 0, 300));

            return;
        }

        Audit::log('brands.mail_test_sent', $this->brand, ['to' => $to], 'Test email sent', brand: $this->brand, logName: 'brands');

        $this->toastSuccess('Test email sent', 'Check the inbox of '.$to.'.');
    }

    public function render(): View
    {
        return view('brands::livewire.app.email-settings', [
            'provider' => MailProviders::find($this->preset),
            'groups' => MailProviders::grouped(),
            'webmailHost' => MailProviders::webmailHost($this->brand->website),
            'hasPassword' => $this->brand->mailSettings()['password'] !== '',
            'saved' => $this->brand->hasMailSettings(),
        ]);
    }
}
