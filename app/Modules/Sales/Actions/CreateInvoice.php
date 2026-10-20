<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Support\Audit;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\ClientFile;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Support\InvoicePdf;
use App\Modules\Sales\Support\Money;
use App\Modules\Sales\Support\SalePayments;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * An invoice for a sale (user request, 19 Oct 2026): the next number of the
 * brand for that year, the brand's company details and logo at the top, the
 * client below, the deal, what is paid and what is still due, and how to
 * pay. The PDF (DOMPDF: no browser needed on the server) goes into the
 * client's files, on the lead for a front sale, so whoever sold it finds it
 * there too.
 */
final class CreateInvoice
{
    /** @param  array{name: string, email: ?string, phone: ?string, address: ?string}  $billTo */
    public function handle(Sale $sale, array $billTo, string $description, CarbonInterface $issuedOn, ?CarbonInterface $dueOn, ?string $note, User $by): Invoice
    {
        $brand = Brand::query()->findOrFail($sale->brand_id);
        $client = Client::query()->acrossBrands()->findOrFail($sale->client_id);
        $lead = $sale->lead_id !== null ? Lead::query()->acrossBrands()->find($sale->lead_id) : null;
        $settings = $brand->invoiceSettings();

        return DB::transaction(function () use ($sale, $brand, $client, $lead, $settings, $billTo, $description, $issuedOn, $dueOn, $note, $by): Invoice {
            $year = (int) $issuedOn->format('Y');
            $sequence = (int) Invoice::query()->acrossBrands()->where('brand_id', $brand->getKey())->where('year', $year)->lockForUpdate()->max('sequence') + 1;
            $number = $settings['prefix'].'-'.$year.'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);

            $invoice = Invoice::query()->create([
                'brand_id' => $brand->getKey(),
                'sale_id' => $sale->getKey(),
                'client_id' => $client->getKey(),
                'number' => $number,
                'year' => $year,
                'sequence' => $sequence,
                'issued_on' => $issuedOn->toDateString(),
                'due_on' => $dueOn?->toDateString(),
                'bill_to' => array_map(fn (?string $value): ?string => filled($value) ? trim((string) $value) : null, $billTo),
                // In the currency the client was charged in: a client who paid in pounds gets a pound invoice.
                'items' => [['description' => trim($description), 'amount' => $sale->dealAmount()]],
                'total' => $sale->dealAmount(),
                'paid' => min($sale->dealPaid(), $sale->dealAmount()),
                'currency' => $sale->dealCurrency(),
                'note' => filled($note) ? trim((string) $note) : null,
                'created_by' => $by->getKey(),
            ]);

            $pdf = InvoicePdf::render($invoice, $brand, SalePayments::ready() ? $sale->payments()->get() : collect());

            $path = 'clients/'.$brand->getKey().'/'.$client->getKey().'/'.Str::lower((string) Str::ulid()).'.pdf';
            Storage::disk('local')->put($path, $pdf);

            $file = ClientFile::query()->create([
                'brand_id' => $brand->getKey(),
                'fileable_type' => ($lead ?? $client)->getMorphClass(),
                'fileable_id' => ($lead ?? $client)->getKey(),
                'category' => 'invoice',
                'disk' => 'local',
                'path' => $path,
                'original_name' => 'Invoice '.$number.'.pdf',
                'mime' => 'application/pdf',
                'size' => strlen($pdf),
                'uploaded_by' => $by->getKey(),
            ]);

            $invoice->forceFill(['file_id' => $file->getKey()])->save();

            Audit::log(
                'sales.invoice_created',
                $lead ?? $client,
                ['invoice_id' => $invoice->getKey(), 'number' => $number, 'sale_id' => $sale->getKey(), 'total' => (float) $invoice->total, 'paid' => (float) $invoice->paid, 'currency' => $invoice->currency],
                'Invoice '.$number.' made: '.Money::format($invoice->total, $invoice->currency).($invoice->balance() > 0.0 ? ' · '.Money::format($invoice->balance(), $invoice->currency).' due' : ' · paid'),
                brand: $brand,
                causer: $by,
                logName: 'sales',
            );

            return $invoice->setRelation('file', $file);
        });
    }
}
