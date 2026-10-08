<?php

declare(strict_types=1);

namespace App\Modules\Brands\Livewire\App;

use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Support\BrandMailer;
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
 * auto-reply a customer gets after sending a website form.
 */
#[Layout('components.layouts.app')]
#[Title('Email settings')]
final class EmailSettings extends Component
{
    use AuthorizesRequests;
    use WithToasts;

    public Brand $brand;

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
            'provider' => BrandMailer::provider($this->host),
            'hasPassword' => $this->brand->mailSettings()['password'] !== '',
            'saved' => $this->brand->hasMailSettings(),
        ]);
    }
}
