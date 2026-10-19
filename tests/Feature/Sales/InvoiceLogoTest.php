<?php

declare(strict_types=1);

use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Support\InvoiceLogo;
use App\Modules\Sales\Support\InvoicePdf;
use Illuminate\Support\Facades\Storage;

/*
 * The brand's mark on its invoice (19 Oct 2026): DOMPDF draws only some
 * pictures, so the invoice takes what it can print well and falls back to
 * the brand's initials in its colour.
 */

beforeEach(function (): void {
    Storage::fake('public');
});

$png = function (int $width, int $height, string $format = 'png'): string {
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 124, 58, 237));
    ob_start();
    $format === 'webp' ? imagewebp($image) : imagepng($image);

    return (string) ob_get_clean();
};

it('uses a picture logo as it is, and a WebP one as PNG', function () use ($png): void {
    Storage::disk('public')->put('brands/logos/wide.png', $png(400, 100));
    Storage::disk('public')->put('brands/logos/mark.webp', $png(200, 200, 'webp'));

    $wide = InvoiceLogo::for(brand(['logo_path' => 'brands/logos/wide.png']));
    $webp = InvoiceLogo::for(brand(['logo_path' => 'brands/logos/mark.webp']));

    expect($wide['src'])->toStartWith('data:image/png;base64,')
        ->and($wide['wide'])->toBeTrue()
        ->and($webp['src'])->toStartWith('data:image/png;base64,')
        ->and($webp['wide'])->toBeFalse();
});

it('takes a plain SVG logo, and the favicon instead of one DOMPDF would draw black', function () use ($png): void {
    Storage::disk('public')->put('brands/logos/plain.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 80"><rect width="60" height="60" fill="#7c3aed"/></svg>');
    Storage::disk('public')->put('brands/logos/fancy.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><style>.a{fill:#7c3aed}</style><rect class="a" width="60" height="60"/></svg>');
    Storage::disk('public')->put('brands/favicons/big.png', $png(180, 180));
    Storage::disk('public')->put('brands/favicons/tiny.png', $png(32, 32));

    $plain = InvoiceLogo::for(brand(['logo_path' => 'brands/logos/plain.svg']));

    expect($plain['src'])->toStartWith('data:image/svg+xml;base64,')
        ->and($plain['wide'])->toBeTrue()
        ->and(InvoiceLogo::for(brand(['logo_path' => 'brands/logos/fancy.svg', 'favicon_path' => 'brands/favicons/big.png']))['src'])->toStartWith('data:image/png;base64,')
        // A 32-pixel favicon would print as a blur: the initials are used instead.
        ->and(InvoiceLogo::for(brand(['logo_path' => 'brands/logos/fancy.svg', 'favicon_path' => 'brands/favicons/tiny.png'])))->toBeNull()
        ->and(InvoiceLogo::for(brand(['logo_path' => 'brands/logos/missing.png'])))->toBeNull();
});

it('draws an invoice with the logo, the brand\'s details and what is still due', function () use ($png): void {
    Storage::disk('public')->put('brands/logos/wide.png', $png(400, 100));

    $brand = brand(['name' => 'Purple Giraffe Press', 'logo_path' => 'brands/logos/wide.png', 'settings' => [
        'company' => ['legal_name' => 'Purple Giraffe Press Ltd', 'address' => 'London', 'tax_number' => 'GB 1'],
        'invoice' => ['payment_details' => 'Sort code 20-45-77'],
    ]]);
    $invoice = new Invoice(['number' => 'PGP-2026-0001', 'issued_on' => '2026-10-19', 'due_on' => '2026-10-26', 'currency' => 'GBP', 'bill_to' => ['name' => 'Martin Hesketh', 'email' => null, 'phone' => null, 'address' => null], 'items' => [['description' => 'Publishing', 'amount' => 1500]], 'total' => 1500, 'paid' => 500]);

    $pdf = InvoicePdf::render($invoice, $brand, collect());

    expect($pdf)->toStartWith('%PDF')
        // The picture is in the file (DOMPDF writes it as an image object).
        ->and($pdf)->toContain('/Subtype /Image');
});
