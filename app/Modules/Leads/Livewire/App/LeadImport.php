<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Livewire\Concerns\WithSpreadsheetImport;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Actions\CreateLead;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * CSV / Excel import with column mapping, one tab of the workbook at a time:
 * pick the file, open a tab, match its columns to lead fields (guessed from
 * the headings), import, then the next tab. Duplicates inside the brand are
 * merged by email or phone; original dates are kept.
 */
final class LeadImport extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;
    use WithSpreadsheetImport;
    use WithToasts;

    public const FIELDS = [
        'name' => 'Name',
        'email' => 'Email',
        'phone' => 'Phone',
        'message' => 'Message',
        'service' => 'Service or package',
        'status' => 'Status (name)',
        'department' => 'Department (name)',
        'assigned_to' => 'Agent (email)',
        'created_at' => 'Original date',
        'value' => 'Value',
    ];

    public Brand $brand;

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorize('import', Lead::class);
    }

    public function import(): void
    {
        $this->authorize('import', Lead::class);

        if (! $this->mapped('name')) {
            $this->addError('maps.'.$this->tab.'.name', 'Choose the column that holds the name.');

            return;
        }

        $user = $this->user();
        $created = 0;
        $merged = 0;
        $failed = 0;
        $errors = [];

        $statuses = LeadStatus::query()->forBrand($this->brand)->get()->keyBy(fn (LeadStatus $status): string => mb_strtolower($status->name));
        $departments = Department::query()->forBrand($this->brand)->get()->keyBy(fn (Department $department): string => mb_strtolower($department->name));

        foreach ($this->tabRows() as $index => $row) {
            try {
                $data = [
                    'name' => $this->cellFor($row, 'name'),
                    'email' => $this->cellFor($row, 'email'),
                    'phone' => $this->cellFor($row, 'phone'),
                    'message' => $this->cellFor($row, 'message'),
                    'source_type' => LeadSource::Import,
                    'imported_at' => now(),
                ];

                if (blank($data['name']) && blank($data['email']) && blank($data['phone'])) {
                    continue;
                }

                if ($service = $this->cellFor($row, 'service')) {
                    $data['fields'] = ['service' => $service];
                }

                if ($status = $this->cellFor($row, 'status')) {
                    $data['status_id'] = $statuses[mb_strtolower($status)]?->getKey();
                }

                if ($department = $this->cellFor($row, 'department')) {
                    $data['department_id'] = $departments[mb_strtolower($department)]?->getKey();
                }

                if ($agentEmail = $this->cellFor($row, 'assigned_to')) {
                    $agent = User::query()->where('email', mb_strtolower($agentEmail))->first();

                    if ($agent !== null && $agent->isMemberOf($this->brand)) {
                        $data['assigned_to'] = $agent->getKey();
                        $data['department_id'] ??= $agent->departmentIds($this->brand)[0] ?? null;
                    }
                }

                if ($date = $this->cellFor($row, 'created_at')) {
                    $data['created_at'] = is_numeric($date) && (float) $date > 20000
                        ? CarbonImmutable::createFromTimestampUTC(((float) $date - 25569) * 86400)
                        : CarbonImmutable::parse($date);
                }

                if (($value = $this->cellFor($row, 'value')) !== null && is_numeric($value)) {
                    $data['value'] = $value;
                    $data['currency'] = $this->brand->currency;
                }

                $result = app(CreateLead::class)->handle($this->brand, $data, $user);

                $result->wasMerged ? $merged++ : $created++;
            } catch (Throwable $exception) {
                $failed++;

                if (count($errors) < 10) {
                    $errors[] = 'Row '.($index + 2).': '.$exception->getMessage();
                }
            }
        }

        $tab = $this->tabName();

        Audit::log('leads.imported', null, ['created' => $created, 'merged' => $merged, 'failed' => $failed, 'file' => $this->fileName(), 'tab' => $tab ?: null], "Imported {$created} leads ({$merged} merged, {$failed} failed)".($tab !== '' ? " from the tab {$tab}" : ''), brand: $this->brand, logName: 'leads');

        $this->finishTab(compact('created', 'merged', 'failed', 'errors'));

        $this->toastSuccess("{$created} ".str('lead')->plural($created).' imported', $merged > 0 ? "{$merged} matched existing leads." : null);
        $this->dispatch('leads-imported');
    }

    public function render(): View
    {
        return view('core::livewire.spreadsheet-import', [
            'title' => 'Import leads from a CSV or Excel file',
            'description' => 'Every tab of a workbook can be imported, one after the other. Original dates are kept and every row is tagged Imported.',
            'fields' => self::FIELDS,
            'required' => ['name'],
            'noun' => 'lead',
            'help' => 'Name is required. Duplicates inside this brand are merged by email or phone.',
        ]);
    }

    /** @return array<string, list<string>> */
    protected function aliases(): array
    {
        return [
            'name' => ['name', 'full name', 'contact', 'lead', 'client', 'customer'],
            'email' => ['email', 'e-mail', 'email address'],
            'phone' => ['phone', 'telephone', 'mobile', 'number', 'phone number', 'contact number'],
            'message' => ['message', 'notes', 'enquiry', 'comment'],
            'service' => ['service', 'package', 'interest'],
            'status' => ['status', 'stage'],
            'department' => ['department', 'team'],
            'assigned_to' => ['agent', 'assigned', 'owner'],
            'created_at' => ['date', 'created', 'received', 'submitted'],
            'value' => ['value', 'amount', 'budget'],
        ];
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
