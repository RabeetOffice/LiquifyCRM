<?php

declare(strict_types=1);

namespace App\Modules\Sales\Livewire\Concerns;

use App\Modules\Brands\Models\Brand;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\ContactVisibility;
use App\Modules\Sales\Actions\CreateInvoice;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Create invoice" under a sale, on the lead and on the client (19 Oct 2026):
 * the sheet opens filled in from the client and the sale, the person checks
 * it, and the PDF downloads at once (it is also kept in the client's files).
 * A contact the person sees masked is not filled in for them: it would
 * uncover what Reveal keeps covered.
 */
trait CreatesInvoices
{
    public ?int $invoiceSale = null;

    public bool $showInvoice = false;

    public string $invoiceName = '';

    public string $invoiceEmail = '';

    public string $invoicePhone = '';

    public string $invoiceAddress = '';

    public string $invoiceItem = '';

    public string $invoiceDate = '';

    public string $invoiceDue = '';

    public string $invoiceNote = '';

    public bool $invoiceMasked = false;

    public function openInvoice(int $saleId): void
    {
        $sale = $this->invoicableSale($saleId);
        $client = Client::query()->acrossBrands()->findOrFail($sale->client_id);
        $lead = $sale->lead_id !== null ? Lead::query()->acrossBrands()->find($sale->lead_id) : null;
        $settings = Brand::query()->findOrFail($sale->brand_id)->invoiceSettings();
        $full = $lead === null || app(ContactVisibility::class)->canSeeFull($this->user(), $lead);

        $this->invoiceSale = $saleId;
        $this->invoiceName = $client->name;
        $this->invoiceEmail = $full ? (string) ($client->email ?? $lead?->email) : '';
        $this->invoicePhone = $full ? (string) ($client->phone ?? $lead?->phone) : '';
        $this->invoiceAddress = (string) ($lead->fields['address'] ?? '');
        $this->invoiceItem = (string) ($sale->service ?: 'Services');
        $this->invoiceDate = now()->toDateString();
        $this->invoiceDue = $settings['due_days'] > 0 ? now()->addDays($settings['due_days'])->toDateString() : '';
        $this->invoiceNote = $settings['note'];
        $this->invoiceMasked = ! $full;
        $this->resetErrorBag();
        $this->showInvoice = true;
    }

    public function createInvoice(): ?StreamedResponse
    {
        $sale = $this->invoicableSale((int) $this->invoiceSale);

        $data = $this->validate([
            'invoiceName' => ['required', 'string', 'max:190'],
            'invoiceEmail' => ['nullable', 'email', 'max:190'],
            'invoicePhone' => ['nullable', 'string', 'max:40'],
            'invoiceAddress' => ['nullable', 'string', 'max:500'],
            'invoiceItem' => ['required', 'string', 'max:190'],
            'invoiceDate' => ['required', 'date'],
            'invoiceDue' => ['nullable', 'date', 'after_or_equal:invoiceDate'],
            'invoiceNote' => ['nullable', 'string', 'max:1000'],
        ], attributes: ['invoiceName' => 'name', 'invoiceEmail' => 'email', 'invoicePhone' => 'phone', 'invoiceAddress' => 'address', 'invoiceItem' => 'description', 'invoiceDate' => 'date', 'invoiceDue' => 'due date', 'invoiceNote' => 'note']);

        $invoice = app(CreateInvoice::class)->handle(
            $sale,
            ['name' => $data['invoiceName'], 'email' => $data['invoiceEmail'], 'phone' => $data['invoicePhone'], 'address' => $data['invoiceAddress']],
            $data['invoiceItem'],
            CarbonImmutable::parse($data['invoiceDate']),
            filled($data['invoiceDue']) ? CarbonImmutable::parse($data['invoiceDue']) : null,
            $data['invoiceNote'],
            $this->user(),
        );

        $this->showInvoice = false;
        $this->invoiceCreated();
        $this->toastSuccess('Invoice '.$invoice->number.' made', 'It is downloading, and it is kept in the client\'s files.');

        $file = $invoice->file;

        return $file !== null ? Storage::disk($file->disk)->download($file->path, $file->original_name, ['Content-Type' => 'application/pdf']) : null;
    }

    /** After an invoice: the screen redraws what depends on it (its file in the list). */
    protected function invoiceCreated(): void {}

    private function invoicableSale(int $saleId): Sale
    {
        $sale = Sale::query()->findOrFail($saleId);
        $this->authorize('invoice', $sale);

        return $sale;
    }
}
