<?php

declare(strict_types=1);

namespace App\Modules\Sites\Support;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Enums\GlobalRole;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Sites\Enums\CheckStatus;
use App\Modules\Sites\Enums\CheckType;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\SiteCheck;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The per-site health checks ported from LeadsHub (includes/checks.php):
 * website is up, SSL expiry, form handler, and (through the optional agent
 * or the pull-mode database) email and database. Each run stores one row per
 * check, sets the red / amber / green of the website and warns the Admins
 * once when a website has been quiet for 48 hours.
 *
 * @phpstan-type Result array{type: CheckType, status: CheckStatus, message: string, ms: ?int}
 */
final class SiteHealthChecker
{
    public function __construct(
        private readonly CertificateReader $certificates,
        private readonly HubAgentClient $agent,
        private readonly SiteDatabase $database,
        private readonly SiteIdentity $identity,
    ) {}

    public function run(Site $site): CheckStatus
    {
        [$uptime, $homepage] = $this->uptime($site);

        $results = [$uptime, $this->ssl($site), $this->form($site), ...$this->emailAndDatabase($site)];
        $now = now();

        foreach ($results as $result) {
            SiteCheck::query()->create([
                'site_id' => $site->getKey(),
                'type' => $result['type'],
                'status' => $result['status'],
                'message' => mb_substr($result['message'], 0, 500),
                'response_ms' => $result['ms'],
                'checked_at' => $now,
            ]);
        }

        $overall = collect($results)
            ->map(fn (array $result): CheckStatus => $result['status'])
            ->sortByDesc(fn (CheckStatus $status): int => $status->severity())
            ->first() ?? CheckStatus::Unknown;

        if ($overall->severity() === 0) {
            $overall = CheckStatus::Unknown;
        }

        $site->forceFill(['health_status' => $overall, 'last_checked_at' => $now])->save();

        if ($homepage !== null) {
            $this->identity->refresh($site, $homepage);
        }

        $this->warnWhenQuiet($site);

        SiteCheck::query()->where('site_id', $site->getKey())->where('checked_at', '<', now()->subDays(30))->delete();

        return $overall;
    }

    /**
     * @return array{0: Result, 1: ?string} the result and the homepage HTML when it loaded
     */
    private function uptime(Site $site): array
    {
        $started = microtime(true);

        try {
            $response = $this->http(20)->get($site->url.'/');
        } catch (Throwable $exception) {
            return [$this->result(CheckType::Uptime, CheckStatus::Fail, 'The website could not be reached. Check the address, DNS and hosting. ('.$this->short($exception).')', $this->ms($started)), null];
        }

        $ms = $this->ms($started);
        $code = $response->status();

        if ($code >= 400) {
            $status = in_array($code, [401, 403], true) ? CheckStatus::Warn : CheckStatus::Fail;

            return [$this->result(CheckType::Uptime, $status, "The homepage answered with error {$code}.", $ms), null];
        }

        if ($ms > 4000) {
            return [$this->result(CheckType::Uptime, CheckStatus::Warn, "The homepage is slow: {$ms} ms. Check hosting resources and caching.", $ms), $response->body()];
        }

        return [$this->result(CheckType::Uptime, CheckStatus::Ok, "Up, answered in {$ms} ms.", $ms), $response->body()];
    }

    /** @return Result */
    private function ssl(Site $site): array
    {
        if (! str_starts_with(mb_strtolower($site->url), 'https://')) {
            return $this->result(CheckType::Ssl, CheckStatus::Warn, 'The website address is not https, so form data is not encrypted.');
        }

        $started = microtime(true);
        $certificate = $this->certificates->read((string) parse_url($site->url, PHP_URL_HOST));
        $ms = $this->ms($started);

        if (! $certificate['ok']) {
            return $this->result(CheckType::Ssl, CheckStatus::Fail, 'The certificate is invalid, expired or for another name: '.$certificate['error'].'. Re-issue it in the hosting panel.', $ms);
        }

        $days = $certificate['days'];
        $issuer = $certificate['issuer'] !== '' ? ' ('.$certificate['issuer'].')' : '';

        return match (true) {
            $days < 0 => $this->result(CheckType::Ssl, CheckStatus::Fail, 'The certificate expired on '.$certificate['expires'].'.', $ms),
            $days <= 14 => $this->result(CheckType::Ssl, CheckStatus::Warn, "The certificate expires in {$days} days, on ".$certificate['expires'].'. Renew it now.', $ms),
            default => $this->result(CheckType::Ssl, CheckStatus::Ok, 'Valid until '.$certificate['expires']." ({$days} days){$issuer}.", $ms),
        };
    }

    /** @return Result */
    private function form(Site $site): array
    {
        $quiet = $site->isQuiet()
            ? 'No lead for '.$site->last_lead_at?->diffForHumans(['syntax' => CarbonInterface::DIFF_ABSOLUTE]).'. Submit the form once to make sure it still works.'
            : null;

        // A page builder form has no handler file to look for.
        if ($site->usesWebhook()) {
            return $quiet !== null
                ? $this->result(CheckType::Form, CheckStatus::Warn, $quiet)
                : $this->result(CheckType::Form, $site->last_lead_at !== null ? CheckStatus::Ok : CheckStatus::Skip, $site->last_lead_at !== null ? 'Last lead '.$site->last_lead_at->diffForHumans().'.' : 'Waiting for the first lead.');
        }

        $started = microtime(true);
        $url = $site->pageUrl($site->formPath());

        try {
            // A GET on a handler that only accepts POST often answers 405 or redirects; only "not found" is a problem.
            $code = $this->http(15)->get($url)->status();
        } catch (Throwable $exception) {
            return $this->result(CheckType::Form, CheckStatus::Fail, 'The form handler could not be reached. ('.$this->short($exception).')', $this->ms($started));
        }

        $ms = $this->ms($started);

        if ($code === 404) {
            return $this->result(CheckType::Form, CheckStatus::Fail, 'The form handler was not found at '.$site->formPath().'. Correct the form handler path of this website, or upload the file.', $ms);
        }

        if ($code >= 500) {
            return $this->result(CheckType::Form, CheckStatus::Fail, "The form handler answered with error {$code}. Check the error log of the website.", $ms);
        }

        if ($quiet !== null) {
            return $this->result(CheckType::Form, CheckStatus::Warn, $quiet, $ms);
        }

        return $this->result(CheckType::Form, CheckStatus::Ok, 'The form handler is in place'.($site->last_lead_at !== null ? ', last lead '.$site->last_lead_at->diffForHumans() : '').'.', $ms);
    }

    /**
     * Email and database need a view from inside the website: the LeadsHub
     * agent reports both; the pull-mode database covers the database check.
     *
     * @return list<Result>
     */
    private function emailAndDatabase(Site $site): array
    {
        $agent = $site->agentConfig();

        if ($agent !== null) {
            $diagnostics = $this->agent->call($agent, 'diagnostics', [], 40);

            if (! $diagnostics['ok']) {
                return [
                    $this->result(CheckType::Smtp, CheckStatus::Fail, 'The health agent did not answer: '.$diagnostics['error'], $diagnostics['ms']),
                    $this->result(CheckType::Database, CheckStatus::Fail, 'The health agent did not answer: '.$diagnostics['error'], $diagnostics['ms']),
                ];
            }

            $checks = collect((array) ($diagnostics['data']['checks'] ?? []))->filter(fn (mixed $check): bool => is_array($check));

            return [
                $this->fromAgent(CheckType::Smtp, $checks->where('group', 'email')->all(), 'Email is sending.'),
                $this->fromAgent(CheckType::Database, $checks->where('group', 'database')->all(), 'The leads database is working.'),
            ];
        }

        $skipped = 'Needs the health agent on the website. Add it under Settings on this page.';
        $database = $site->databaseConfig();

        if ($database === null) {
            return [
                $this->result(CheckType::Smtp, CheckStatus::Skip, $skipped),
                $this->result(CheckType::Database, CheckStatus::Skip, $skipped),
            ];
        }

        $started = microtime(true);

        try {
            $rows = $this->database->count($database);
            $result = $this->result(CheckType::Database, CheckStatus::Ok, "Connected. {$rows} rows in the {$database['table']} table.", $this->ms($started));
        } catch (Throwable $exception) {
            $result = $this->result(CheckType::Database, CheckStatus::Fail, 'Could not read the leads table: '.$this->short($exception), $this->ms($started));
        }

        return [$this->result(CheckType::Smtp, CheckStatus::Skip, $skipped), $result];
    }

    /**
     * Collapse the agent's detailed checks of one group into one result: the
     * worst status wins and its message (with the "how to fix" note) is shown.
     *
     * @param  array<array-key, array<string, mixed>>  $checks
     * @return Result
     */
    private function fromAgent(CheckType $type, array $checks, string $allGood): array
    {
        $worst = null;
        $worstStatus = CheckStatus::Skip;
        $ms = 0;

        foreach ($checks as $check) {
            $status = CheckStatus::tryFrom((string) ($check['status'] ?? '')) ?? CheckStatus::Skip;
            $ms += (int) ($check['ms'] ?? 0);

            if ($status->severity() > $worstStatus->severity()) {
                $worstStatus = $status;
                $worst = $check;
            }
        }

        if ($worst === null) {
            return $this->result($type, CheckStatus::Skip, 'The website has nothing set up for this check.');
        }

        if ($worstStatus === CheckStatus::Ok) {
            return $this->result($type, CheckStatus::Ok, $allGood, $ms);
        }

        $message = trim(($worst['label'] ?? 'Check').': '.($worst['message'] ?? ''));
        $fix = trim((string) ($worst['fix'] ?? ''));

        return $this->result($type, $worstStatus, $message.($fix !== '' ? ' '.$fix : ''), $ms);
    }

    /** Amber on the Websites screen plus one email to the Admins; reset when the next lead arrives. */
    private function warnWhenQuiet(Site $site): void
    {
        if (! $site->isQuiet() || $site->quiet_notified_at !== null) {
            return;
        }

        $site->loadMissing('brand');
        $site->forceFill(['quiet_notified_at' => now()])->save();

        $admins = User::query()->whereIn('role', [GlobalRole::SuperAdmin->value, GlobalRole::Admin->value])->get();

        Notification::send($admins, new AppNotification(
            $site->name.' has been quiet for '.(int) config('liquify.sites.quiet_hours', 48).' hours',
            'No lead has arrived from '.$site->domain.' since '.$site->last_lead_at?->diffForHumans().'. Submit its form once to check it still works.',
            'warning',
            $site->brand->url('sites/'.$site->getKey()),
            mail: true,
            icon: 'heroicon-o-globe-alt',
            actionLabel: 'Open website',
        ));
    }

    private function http(int $timeout): PendingRequest
    {
        return Http::timeout($timeout)->connectTimeout(10)->withUserAgent('LiquifyCRM health check')->withOptions(['allow_redirects' => ['max' => 5]]);
    }

    /** @return Result */
    private function result(CheckType $type, CheckStatus $status, string $message, ?int $ms = null): array
    {
        return ['type' => $type, 'status' => $status, 'message' => $message, 'ms' => $ms];
    }

    private function ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    private function short(Throwable $exception): string
    {
        return mb_substr($exception->getMessage(), 0, 160);
    }
}
