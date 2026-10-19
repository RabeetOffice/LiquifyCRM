<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Modules\Brands\Models\Brand;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\SalePayment;
use Illuminate\Support\Collection;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * The invoice as a PDF (DOMPDF, so the server needs no browser): the brand's
 * mark and colour, its company and bank details, the client, the deal, what
 * is paid and what is still due. `sales::invoices.pdf` draws it.
 */
final class InvoicePdf
{
    /** @param  Collection<int, SalePayment>  $payments */
    public static function render(Invoice $invoice, Brand $brand, Collection $payments): string
    {
        $accent = self::readable($brand->primaryColor() ?? '#FF6B2C');
        $company = $brand->company();
        $balance = $invoice->balance();

        return Pdf::view('sales::invoices.pdf', [
            'invoice' => $invoice,
            'brand' => $brand,
            'company' => $company,
            'legalName' => $company['legal_name'] !== '' ? $company['legal_name'] : $brand->name,
            'website' => $brand->website ? (string) preg_replace('~^https?://(www\.)?~i', '', rtrim((string) $brand->website, '/')) : '',
            'paymentDetails' => $brand->invoiceSettings()['payment_details'],
            'payments' => $payments,
            'logo' => InvoiceLogo::for($brand),
            // Up to two letters of the name, for a brand with no picture a PDF can draw.
            'initials' => mb_strtoupper(collect(preg_split('/\s+/', trim($brand->name)) ?: [])->filter()->take(2)->map(fn (string $word): string => mb_substr($word, 0, 1))->implode('')) ?: '?',
            'accent' => $accent,
            'tint' => self::mix($accent, 0.9),
            'status' => match (true) {
                $balance <= 0.0 => ['label' => 'Paid', 'color' => '#15803D', 'tint' => '#F0FDF4'],
                (float) $invoice->paid > 0.0 => ['label' => 'Part paid', 'color' => '#B45309', 'tint' => '#FFFBEB'],
                default => ['label' => 'Unpaid', 'color' => '#B91C1C', 'tint' => '#FEF2F2'],
            },
        ])->driver('dompdf')->format('a4')->generatePdfContent();
    }

    /** A brand colour too light to read as text on white is darkened until it is. */
    private static function readable(string $hex): string
    {
        [$r, $g, $b] = self::rgb($hex);

        while ((0.299 * $r + 0.587 * $g + 0.114 * $b) > 150) {
            [$r, $g, $b] = [(int) ($r * 0.85), (int) ($g * 0.85), (int) ($b * 0.85)];
        }

        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    /** The colour mixed with white: 0.9 is a soft tint for a band or a box. */
    private static function mix(string $hex, float $white): string
    {
        [$r, $g, $b] = self::rgb($hex);

        return sprintf('#%02X%02X%02X', (int) round($r + (255 - $r) * $white), (int) round($g + (255 - $g) * $white), (int) round($b + (255 - $b) * $white));
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (! preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return [255, 107, 44];
        }

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }
}
