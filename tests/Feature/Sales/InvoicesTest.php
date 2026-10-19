<?php

declare(strict_types=1);

use App\Modules\Brands\Livewire\Admin\BrandForm;
use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Actions\CreateInvoice;
use App\Modules\Sales\Actions\HandOverClient;
use App\Modules\Sales\Actions\RecordSale;
use App\Modules\Sales\Livewire\App\ClientShow;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\ClientFile;
use App\Modules\Sales\Models\Invoice;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Invoices (user request, 19 Oct 2026): made from a sale on the lead or the
 * client, numbered per brand and year, with the brand's company and bank
 * details; the PDF downloads and is kept in the client's files.
 */

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph', 'currency' => 'GBP', 'settings' => [
        'company' => ['legal_name' => 'UK Publishing House Ltd', 'address' => '71-75 Shelton Street, London', 'tax_number' => 'GB123456789'],
        'invoice' => ['payment_details' => 'Sort code 20-00-00, account 12345678', 'note' => 'Thank you.', 'due_days' => 14],
    ]]);
    $this->front = department($this->brand, ['name' => 'Front Sales']);
    $this->upsell = department($this->brand, ['name' => 'Upsell', 'kind' => 'upsell']);
    $this->seller = agentOf($this->front, ['name' => 'Sami Seller']);
    $this->colleague = agentOf($this->front, ['name' => 'Omar Other']);
    $this->upseller = agentOf($this->upsell, ['name' => 'Usman Upseller']);
    $this->admin = admin();

    inBrand($this->brand);

    $this->lead = Lead::factory()->create([
        'brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'assigned_to' => $this->seller->getKey(),
        'name' => 'Sarah Lee', 'email' => 'sarah@example.com', 'phone' => '+447700900123', 'fields' => ['address' => '1 Rose Lane, Leeds'],
    ]);
    $this->sale = app(RecordSale::class)->handle($this->lead, 1500, 'Publishing package', now(), $this->seller, 500);
});

it('makes an invoice from the sale on the lead, numbered for the brand, and keeps its PDF with the client', function (): void {
    $panel = Livewire::actingAs($this->admin)
        ->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->assertSee('wire:click="openInvoice(', false)
        ->call('openInvoice', $this->sale->getKey())
        ->assertSet('invoiceName', 'Sarah Lee')
        ->assertSet('invoiceEmail', 'sarah@example.com')
        ->assertSet('invoiceAddress', '1 Rose Lane, Leeds')
        ->assertSet('invoiceItem', 'Publishing package')
        ->assertSet('invoiceDue', now()->addDays(14)->toDateString())
        ->assertSet('invoiceNote', 'Thank you.')
        ->call('createInvoice')
        ->assertHasNoErrors()
        ->assertFileDownloaded('Invoice UKPH-'.now()->year.'-0001.pdf');

    $invoice = Invoice::query()->sole();

    expect($invoice->number)->toBe('UKPH-'.now()->year.'-0001')
        ->and((float) $invoice->total)->toBe(1500.0)
        ->and((float) $invoice->paid)->toBe(500.0)
        ->and($invoice->balance())->toBe(1000.0)
        ->and($invoice->bill_to['name'])->toBe('Sarah Lee')
        ->and($invoice->file->category)->toBe('invoice')
        ->and($invoice->file->leadId())->toBe($this->lead->getKey())
        ->and(ClientFile::query()->ofClient(Client::query()->sole())->where('category', 'invoice')->count())->toBe(1)
        ->and($this->lead->activities()->where('event', 'sales.invoice_created')->count())->toBe(1);

    expect(Storage::disk('local')->get($invoice->file->path))->toStartWith('%PDF');

    // The next one takes the next number.
    $second = app(CreateInvoice::class)->handle($this->sale, ['name' => 'Sarah Lee', 'email' => null, 'phone' => null, 'address' => null], 'Balance', now(), null, null, $this->admin);
    expect($second->number)->toBe('UKPH-'.now()->year.'-0002');
});

it('leaves out a contact the person sees masked, and lets only the people on the sale make an invoice', function (): void {
    // The seller sees the contact masked until they reveal it: the invoice does not uncover it.
    Livewire::actingAs($this->seller)
        ->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->call('openInvoice', $this->sale->getKey())
        ->assertSet('invoiceEmail', '')
        ->assertSet('invoicePhone', '')
        ->assertSet('invoiceMasked', true)
        ->assertSee('masked for you');

    expect($this->seller->can('invoice', $this->sale))->toBeTrue()
        ->and($this->colleague->can('invoice', $this->sale))->toBeFalse()
        ->and($this->upseller->can('invoice', $this->sale))->toBeFalse();

    Livewire::actingAs($this->colleague)->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->assertDontSee('wire:click="openInvoice(', false)
        ->call('openInvoice', $this->sale->getKey())
        ->assertForbidden();

    // Once the client is with Upsell, the person looking after them invoices from the client page.
    app(HandOverClient::class)->handle(Client::query()->sole(), $this->upsell, $this->upseller, $this->seller);

    Livewire::actingAs($this->upseller->fresh())
        ->test(ClientShow::class, ['brand' => $this->brand, 'client' => Client::query()->sole()])
        ->assertSee('wire:click="openInvoice(', false)
        ->call('openInvoice', $this->sale->getKey())
        ->call('createInvoice')
        ->assertHasNoErrors();

    expect(Invoice::query()->count())->toBe(1);
});

it('keeps the invoice details of a brand in its settings, edited by an Admin', function (): void {
    expect($this->brand->invoiceSettings()['prefix'])->toBe('UKPH')
        ->and(brand(['name' => 'Purple Giraffe Press'])->invoiceSettings()['prefix'])->toBe('PGP');

    Livewire::actingAs($this->admin)
        ->test(BrandForm::class, ['brandId' => $this->brand->getKey()])
        ->assertSet('invoicePayment', 'Sort code 20-00-00, account 12345678')
        ->set('invoicePrefix', 'ukp')
        ->set('invoiceDueDays', 30)
        ->call('save')
        ->assertHasNoErrors();

    $settings = $this->brand->fresh()->invoiceSettings();

    expect($settings['prefix'])->toBe('UKP')
        ->and($settings['due_days'])->toBe(30)
        ->and($this->brand->fresh()->company()['legal_name'])->toBe('UK Publishing House Ltd');
});
