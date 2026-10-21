<?php

declare(strict_types=1);

namespace App\Modules\Sales\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Livewire\Concerns\WithSpreadsheetImport;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use App\Modules\Core\Support\DashboardCache;
use App\Modules\Core\Support\ViewerTime;
use App\Modules\Departments\Models\Department;
use App\Modules\Sales\Actions\ImportClient;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Support\ClientCare;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use InvalidArgumentException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Clients from a CSV or Excel file, one tab of the workbook at a time (the
 * same screen as the lead import). Each row is a client and what they
 * bought (ImportClient); a care team and the person who looks after them
 * can come from the sheet too. Admins only.
 */
final class ClientImport extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;
    use WithSpreadsheetImport;
    use WithToasts;

    public const FIELDS = [
        'name' => 'Name',
        'email' => 'Email',
        'phone' => 'Phone',
        'amount' => 'Amount spent',
        'service' => 'What they bought',
        'closed_at' => 'Date of purchase',
        'department' => 'Care team (name)',
        'owner' => 'Looks after (email)',
    ];

    public Brand $brand;

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorize('import', Client::class);
    }

    public function import(): void
    {
        $this->authorize('import', Client::class);

        if (! $this->mapped('name')) {
            $this->addError('maps.'.$this->tab.'.name', 'Choose the column that holds the name.');

            return;
        }

        $user = $this->user();
        $created = 0;
        $merged = 0;
        $failed = 0;
        $errors = [];

        $departments = Department::query()->forBrand($this->brand)->get()->keyBy(fn (Department $department): string => mb_strtolower($department->name));
        $care = ClientCare::ready();

        foreach ($this->tabRows() as $index => $row) {
            try {
                $name = $this->cellFor($row, 'name');
                $email = $this->cellFor($row, 'email');
                $phone = $this->cellFor($row, 'phone');

                if (blank($name) && blank($email) && blank($phone)) {
                    continue;
                }

                if (blank($name)) {
                    throw new InvalidArgumentException('The name is empty.');
                }

                // "£1,250.50" is 1250.50; a word is not an amount.
                $amount = $this->cellFor($row, 'amount');
                $amount = $amount === null ? null : (string) preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $amount));

                if ($amount !== null && ! is_numeric($amount)) {
                    throw new InvalidArgumentException('The amount is not a number.');
                }

                $department = null;
                $owner = null;

                if ($care && ($team = $this->cellFor($row, 'department')) !== null) {
                    $department = $departments[mb_strtolower($team)] ?? throw new InvalidArgumentException('There is no team called '.$team.'.');
                }

                if ($care && ($ownerEmail = $this->cellFor($row, 'owner')) !== null) {
                    $owner = User::query()->where('email', mb_strtolower($ownerEmail))->first();

                    if ($owner === null || ! $owner->isMemberOf($this->brand)) {
                        throw new InvalidArgumentException('Nobody in this brand has the email '.$ownerEmail.'.');
                    }

                    // No team on the row: the Upsell team the person is in.
                    $department ??= $departments->first(fn (Department $team): bool => $team->isUpsell() && $team->hasMember($owner));
                }

                $isNew = app(ImportClient::class)->handle($this->brand, [
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'amount' => $amount === null ? null : (float) $amount,
                    'service' => $this->cellFor($row, 'service'),
                    'closed_at' => $this->date($this->cellFor($row, 'closed_at')),
                    'department' => $department,
                    'owner' => $owner,
                ], $user);

                $isNew ? $created++ : $merged++;
            } catch (Throwable $exception) {
                $failed++;

                if (count($errors) < 10) {
                    $errors[] = 'Row '.($index + 2).': '.$exception->getMessage();
                }
            }
        }

        DashboardCache::flush((int) $this->brand->getKey());

        $tab = $this->tabName();

        Audit::log('clients.imported', null, ['created' => $created, 'merged' => $merged, 'failed' => $failed, 'file' => $this->fileName(), 'tab' => $tab ?: null], "Imported {$created} clients ({$merged} matched, {$failed} failed)".($tab !== '' ? " from the tab {$tab}" : ''), brand: $this->brand, logName: 'sales');

        $this->finishTab(compact('created', 'merged', 'failed', 'errors'));

        $this->toastSuccess("{$created} ".str('client')->plural($created).' imported', $merged > 0 ? "{$merged} matched existing clients." : null);
        $this->dispatch('clients-imported');
    }

    public function render(): View
    {
        $fields = self::FIELDS;

        if (! ClientCare::ready()) {
            unset($fields['department'], $fields['owner']);
        }

        return view('core::livewire.spreadsheet-import', [
            'title' => 'Import clients from a CSV or Excel file',
            'description' => 'Every tab of a workbook can be imported, one after the other. Each row is a client and what they bought; nobody is notified.',
            'fields' => $fields,
            'required' => ['name'],
            'noun' => 'client',
            'help' => 'Name is required. Each row is recorded as a sale: without an amount it counts as 0, without a date it is dated today. The same email or phone joins the client already here, and the same amount on the same day is not added twice.',
        ]);
    }

    /** @return array<string, list<string>> */
    protected function aliases(): array
    {
        return [
            'name' => ['name', 'full name', 'client', 'client name', 'customer', 'customer name', 'contact'],
            'email' => ['email', 'e-mail', 'email address'],
            'phone' => ['phone', 'telephone', 'mobile', 'number', 'phone number', 'contact number'],
            'amount' => ['amount', 'amount spent', 'spent', 'total', 'value', 'price', 'paid', 'revenue', 'deal', 'sale'],
            'service' => ['service', 'package', 'product', 'bought', 'what they bought', 'project'],
            'closed_at' => ['date', 'sale date', 'purchase date', 'date of purchase', 'order date', 'closed', 'created'],
            'department' => ['team', 'department', 'care team'],
            'owner' => ['owner', 'looks after', 'account manager', 'project manager', 'manager', 'agent', 'assigned'],
        ];
    }

    /** A date as the sheet has it (an Excel day number or text, in the viewer's zone); today without one. */
    private function date(?string $value): CarbonImmutable
    {
        if ($value === null) {
            return CarbonImmutable::now();
        }

        if (is_numeric($value) && (float) $value > 20000) {
            return CarbonImmutable::createFromTimestampUTC(((float) $value - 25569) * 86400);
        }

        // 21/10/2026 is the day first, as written in the UK.
        if (preg_match('~^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$~', $value, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[1], (int) $parts[3])) {
            $value = sprintf('%04d-%02d-%02d', $parts[3], $parts[2], $parts[1]);
        }

        return ViewerTime::parse($value) ?? throw new InvalidArgumentException('The date '.$value.' could not be read.');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
