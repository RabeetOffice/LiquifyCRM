<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
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
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * CSV / Excel import with column mapping: pick the file, match its columns
 * to lead fields (guessed from the headings), import. Duplicates inside the
 * brand are merged by email or phone; original dates are kept.
 */
final class LeadImport extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;
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

    public ?TemporaryUploadedFile $file = null;

    /** @var list<string> */
    public array $headers = [];

    /** @var list<list<mixed>> */
    public array $rows = [];

    /** @var array<string, string> field => column index */
    public array $map = [];

    /** @var array{created: int, merged: int, failed: int, errors: list<string>}|null */
    public ?array $result = null;

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorize('import', Lead::class);
    }

    public function updatedFile(): void
    {
        $this->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240']]);

        try {
            $sheets = Excel::toArray(new RawRows, $this->file->getRealPath(), null, $this->readerType());
        } catch (Throwable $exception) {
            $this->addError('file', 'That file could not be read: '.$exception->getMessage());

            return;
        }

        $rows = array_values(array_filter($sheets[0] ?? [], fn (array $row): bool => array_filter($row, fn ($cell): bool => $cell !== null && $cell !== '') !== []));

        if ($rows === []) {
            $this->addError('file', 'The file is empty.');

            return;
        }

        $this->headers = array_map(fn ($cell): string => trim((string) $cell), array_shift($rows));
        $this->rows = $rows;
        $this->result = null;
        $this->guessMapping();
    }

    public function import(): void
    {
        $this->authorize('import', Lead::class);

        if (($this->map['name'] ?? '') === '') {
            $this->addError('map.name', 'Choose the column that holds the name.');

            return;
        }

        $user = $this->user();
        $created = 0;
        $merged = 0;
        $failed = 0;
        $errors = [];

        $statuses = LeadStatus::query()->forBrand($this->brand)->get()->keyBy(fn (LeadStatus $status): string => mb_strtolower($status->name));
        $departments = Department::query()->forBrand($this->brand)->get()->keyBy(fn (Department $department): string => mb_strtolower($department->name));

        foreach ($this->rows as $index => $row) {
            try {
                $data = [
                    'name' => $this->cell($row, 'name'),
                    'email' => $this->cell($row, 'email'),
                    'phone' => $this->cell($row, 'phone'),
                    'message' => $this->cell($row, 'message'),
                    'source_type' => LeadSource::Import,
                    'imported_at' => now(),
                ];

                if (blank($data['name']) && blank($data['email']) && blank($data['phone'])) {
                    continue;
                }

                if ($service = $this->cell($row, 'service')) {
                    $data['fields'] = ['service' => $service];
                }

                if ($status = $this->cell($row, 'status')) {
                    $data['status_id'] = $statuses[mb_strtolower($status)]?->getKey();
                }

                if ($department = $this->cell($row, 'department')) {
                    $data['department_id'] = $departments[mb_strtolower($department)]?->getKey();
                }

                if ($agentEmail = $this->cell($row, 'assigned_to')) {
                    $agent = User::query()->where('email', mb_strtolower($agentEmail))->first();

                    if ($agent !== null && $agent->isMemberOf($this->brand)) {
                        $data['assigned_to'] = $agent->getKey();
                        $data['department_id'] ??= $agent->departmentIds($this->brand)[0] ?? null;
                    }
                }

                if ($date = $this->cell($row, 'created_at')) {
                    $data['created_at'] = is_numeric($date) && (float) $date > 20000
                        ? CarbonImmutable::createFromTimestampUTC(((float) $date - 25569) * 86400)
                        : CarbonImmutable::parse($date);
                }

                if (($value = $this->cell($row, 'value')) !== null && is_numeric($value)) {
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

        Audit::log('leads.imported', null, ['created' => $created, 'merged' => $merged, 'failed' => $failed, 'file' => $this->file?->getClientOriginalName()], "Imported {$created} leads ({$merged} merged, {$failed} failed)", brand: $this->brand, logName: 'leads');

        $this->result = compact('created', 'merged', 'failed', 'errors');
        $this->rows = [];
        $this->headers = [];
        $this->file = null;

        $this->toastSuccess("{$created} ".str('lead')->plural($created).' imported', $merged > 0 ? "{$merged} matched existing leads." : null);
        $this->dispatch('leads-imported');
    }

    public function reset_(): void
    {
        $this->reset('file', 'headers', 'rows', 'map', 'result');
    }

    public function render(): View
    {
        return view('leads::livewire.app.lead-import', [
            'fields' => self::FIELDS,
            'preview' => array_slice($this->rows, 0, 3),
        ]);
    }

    private function guessMapping(): void
    {
        $aliases = [
            'name' => ['name', 'full name', 'contact', 'lead'],
            'email' => ['email', 'e-mail', 'email address'],
            'phone' => ['phone', 'telephone', 'mobile', 'number'],
            'message' => ['message', 'notes', 'enquiry', 'comment'],
            'service' => ['service', 'package', 'interest'],
            'status' => ['status', 'stage'],
            'department' => ['department', 'team'],
            'assigned_to' => ['agent', 'assigned', 'owner'],
            'created_at' => ['date', 'created', 'received', 'submitted'],
            'value' => ['value', 'amount', 'budget'],
        ];

        $this->map = [];

        foreach ($aliases as $field => $names) {
            foreach ($this->headers as $index => $header) {
                if (in_array(mb_strtolower($header), $names, true)) {
                    $this->map[$field] = (string) $index;
                    break;
                }
            }
        }
    }

    /** @param  list<mixed>  $row */
    private function cell(array $row, string $field): ?string
    {
        $index = $this->map[$field] ?? '';

        if ($index === '') {
            return null;
        }

        $value = $row[(int) $index] ?? null;

        return $value === null ? null : (trim((string) $value) ?: null);
    }

    private function readerType(): string
    {
        $extension = mb_strtolower((string) $this->file?->getClientOriginalExtension());

        return match ($extension) {
            'xlsx' => ExcelFormat::XLSX,
            'xls' => ExcelFormat::XLS,
            default => ExcelFormat::CSV,
        };
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
