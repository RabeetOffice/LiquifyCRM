<?php

declare(strict_types=1);

use App\Modules\Leads\Livewire\App\LeadImport;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Livewire\App\ClientImport;
use App\Modules\Sales\Livewire\App\ClientsIndex;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\Sale;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet as Workbook;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
 * Imports from a workbook with several tabs (21 October): every tab is
 * listed, the open tab's rows are shown, and each tab is imported on its
 * own. Clients can be imported too (Admins), each row with what they bought.
 */

/** @param  array<string, list<list<mixed>>>  $tabs  name => rows (the first is the heading row) */
function workbook(array $tabs, string $name = 'book.xlsx'): UploadedFile
{
    $book = new Workbook;
    $book->removeSheetByIndex(0);

    foreach ($tabs as $title => $rows) {
        $book->createSheet()->setTitle($title)->fromArray($rows);
    }

    $path = tempnam(sys_get_temp_dir(), 'lq').'.xlsx';
    (new Xlsx($book))->save($path);

    return UploadedFile::fake()->createWithContent($name, (string) file_get_contents($path));
}

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph', 'currency' => 'GBP']);
    $this->upsell = department($this->brand, ['name' => 'Upsell', 'kind' => 'upsell']);
    $this->upseller = agentOf($this->upsell, ['name' => 'Usman Upseller', 'email' => 'usman@example.com']);
    $this->admin = admin();

    inBrand($this->brand);
});

it('shows every tab of a workbook and imports leads one tab at a time', function (): void {
    $file = workbook([
        'October' => [['Name', 'Email', 'Phone'], ['Ann Able', 'ann@example.com', '07700 900001'], ['Ben Best', 'ben@example.com', '07700 900002']],
        'Empty' => [],
        'November' => [['Full name', 'E-mail'], ['Cara Cole', 'cara@example.com']],
    ]);

    $import = Livewire::actingAs($this->admin)
        ->test(LeadImport::class, ['brand' => $this->brand])
        ->set('file', $file)
        ->assertHasNoErrors()
        ->assertSee(['October', 'Empty', 'November', 'Ann Able'])
        ->assertSet('tab', 0)
        ->assertSet('total', 2)
        ->call('import');

    expect(Lead::query()->pluck('name')->sort()->values()->all())->toBe(['Ann Able', 'Ben Best']);

    $import->call('openTab', 2)
        ->assertSet('total', 1)
        ->assertSee('Cara Cole')
        ->assertSet('maps.2.name', '0')
        ->assertSet('maps.2.email', '1')
        ->call('import')
        ->assertSet('results.0.created', 2)
        ->assertSet('results.2.created', 1);

    expect(Lead::query()->count())->toBe(3)
        ->and(Lead::query()->where('name', 'Cara Cole')->value('email'))->toBe('cara@example.com');

    // Back on the first tab, its columns are as they were matched.
    $import->call('openTab', 0)->assertSet('maps.0.email', '1')->assertSee('Ben Best');
});

it('imports clients with what they bought, their team and the person who looks after them', function (): void {
    $file = workbook([
        'Clients 2025' => [
            ['Client name', 'Email', 'Amount spent', 'Service', 'Date', 'Team', 'Looks after'],
            ['Dana Dee', 'dana@example.com', '£1,250.50', 'Publishing package', '21/03/2025', 'Upsell', 'usman@example.com'],
            ['Eli Eve', 'eli@example.com', '', '', '', '', ''],
            ['Fay Fox', 'fay@example.com', 'lots', '', '', '', ''],
            ['Gus Gale', 'gus@example.com', '300', '', '', 'Front desk', ''],
        ],
    ]);

    $import = Livewire::actingAs($this->admin)
        ->test(ClientImport::class, ['brand' => $this->brand])
        ->set('file', $file)
        ->assertSet('maps.0.name', '0')
        ->assertSet('maps.0.amount', '2')
        ->assertSet('maps.0.owner', '6')
        ->call('import')
        ->assertSet('results.0.created', 2)
        ->assertSet('results.0.failed', 2);

    $dana = Client::query()->where('email', 'dana@example.com')->sole();
    $sale = Sale::query()->where('client_id', $dana->getKey())->sole();

    expect((float) $dana->lifetime_value)->toBe(1250.5)
        ->and($dana->sales_count)->toBe(1)
        ->and($dana->department_id)->toBe($this->upsell->getKey())
        ->and($dana->owner_id)->toBe($this->upseller->getKey())
        ->and($sale->closed_at->toDateString())->toBe('2025-03-21')
        ->and($sale->service)->toBe('Publishing package')
        ->and($sale->lead_id)->toBeNull()
        ->and((float) Client::query()->where('email', 'eli@example.com')->sole()->lifetime_value)->toBe(0.0)
        ->and(collect($import->get('results')[0]['errors'])->implode(' '))->toContain('not a number')->toContain('no team called Front desk');

    // The same sheet again adds nothing.
    $import->call('import')->assertSet('results.0.created', 0)->assertSet('results.0.merged', 2);

    expect(Client::query()->count())->toBe(2)
        ->and(Sale::query()->count())->toBe(2)
        ->and($dana->refresh()->sales_count)->toBe(1);

    Livewire::actingAs($this->admin)->test(ClientsIndex::class, ['brand' => $this->brand])
        ->assertSee('Import clients')
        ->assertSee('Dana Dee');
});

it('keeps the client import for Admins', function (): void {
    Livewire::actingAs($this->upseller)
        ->test(ClientImport::class, ['brand' => $this->brand])
        ->assertForbidden();

    Livewire::actingAs($this->upseller)->test(ClientsIndex::class, ['brand' => $this->brand])
        ->assertDontSee('Import clients');
});
