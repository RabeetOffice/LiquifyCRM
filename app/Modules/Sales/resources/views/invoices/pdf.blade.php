@php
    use App\Modules\Sales\Support\Money;

    $money = fn ($amount): string => Money::format((float) $amount, $invoice->currency);
    $bill = $invoice->bill_to;
    $balance = $invoice->balance();
    $legalName = ($company['legal_name'] ?? '') !== '' ? $company['legal_name'] : $brand->name;
    $from = array_filter([$company['address'] ?? '', $company['phone'] ?? '', $company['email'] ?? '', $brand->website ? preg_replace('~^https?://~', '', rtrim((string) $brand->website, '/')) : '']);
    $ids = array_filter([($company['registration_number'] ?? '') !== '' ? 'Company no. '.$company['registration_number'] : '', ($company['tax_number'] ?? '') !== '' ? 'VAT no. '.$company['tax_number'] : '']);
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->number }}</title>
    {{-- DOMPDF draws tables and plain CSS (no flex, no grid); DejaVu Sans has the £, € and $ signs. --}}
    <style>
        @page { margin: 34px 40px; }
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { margin: 0; color: #1c1917; font-size: 10.5px; line-height: 1.5; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 0; }
        .muted { color: #78716c; }
        .band { height: 6px; background: {{ $accent }}; margin-bottom: 26px; }
        .logo { max-height: 62px; max-width: 220px; }
        .brand { font-size: 15px; font-weight: bold; margin: 8px 0 2px; }
        .title { font-size: 26px; font-weight: bold; letter-spacing: 2px; text-align: right; color: {{ $accent }}; }
        .meta td { padding: 2px 0; }
        .meta .label { color: #78716c; text-align: right; padding-right: 10px; }
        .meta .value { text-align: right; font-weight: bold; width: 1%; white-space: nowrap; }
        .section { margin-top: 28px; }
        .heading { font-size: 9px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; color: #78716c; margin-bottom: 6px; }
        .bill-name { font-size: 13px; font-weight: bold; }
        .lines th { font-size: 9px; letter-spacing: 1px; text-transform: uppercase; color: #78716c; text-align: left; padding: 8px 10px; border-bottom: 1.5px solid #1c1917; }
        .lines th.amount, .lines td.amount { text-align: right; white-space: nowrap; }
        .lines td { padding: 12px 10px; border-bottom: 1px solid #e7e5e4; }
        .totals { width: 46%; margin-left: 54%; margin-top: 14px; }
        .totals td { padding: 5px 10px; }
        .totals .amount { text-align: right; white-space: nowrap; }
        .totals .grand td { font-size: 13px; font-weight: bold; border-top: 1.5px solid #1c1917; padding-top: 8px; }
        .totals .due td { font-weight: bold; color: {{ $balance > 0 ? '#b45309' : '#15803d' }}; }
        .stamp { display: inline-block; margin-top: 4px; padding: 3px 10px; border: 1.5px solid #15803d; color: #15803d; font-weight: bold; letter-spacing: 2px; font-size: 11px; }
        .box { margin-top: 26px; padding: 12px 14px; background: #f5f5f4; border-left: 3px solid {{ $accent }}; }
        .payments td { padding: 2px 0; }
        .footer { position: fixed; bottom: -14px; left: 0; right: 0; text-align: center; font-size: 8.5px; color: #a8a29e; }
    </style>
</head>
<body>
    <div class="band"></div>

    <table>
        <tr>
            <td style="width: 58%;">
                @if ($logo)
                    <img class="logo" src="{{ $logo }}" alt="">
                @endif
                <div class="brand">{{ $legalName }}</div>
                @foreach ($from as $line)
                    <div class="muted">{!! nl2br(e($line)) !!}</div>
                @endforeach
                @if ($ids !== [])
                    <div class="muted">{{ implode(' · ', $ids) }}</div>
                @endif
            </td>
            <td style="width: 42%;">
                <div class="title">INVOICE</div>
                <table class="meta" style="margin-top: 10px;">
                    <tr><td class="label">Number</td><td class="value">{{ $invoice->number }}</td></tr>
                    <tr><td class="label">Date</td><td class="value">{{ $invoice->issued_on->format('j F Y') }}</td></tr>
                    @if ($invoice->due_on)
                        <tr><td class="label">Due</td><td class="value">{{ $invoice->due_on->format('j F Y') }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <div class="section">
        <div class="heading">Bill to</div>
        <div class="bill-name">{{ $bill['name'] ?? '' }}</div>
        @if (! empty($bill['address']))
            <div>{!! nl2br(e($bill['address'])) !!}</div>
        @endif
        @if (! empty($bill['email']))
            <div class="muted">{{ $bill['email'] }}</div>
        @endif
        @if (! empty($bill['phone']))
            <div class="muted">{{ $bill['phone'] }}</div>
        @endif
    </div>

    <table class="lines section">
        <thead>
            <tr><th>Description</th><th class="amount">Amount</th></tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr><td>{{ $item['description'] }}</td><td class="amount">{{ $money($item['amount']) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr class="grand"><td>Total</td><td class="amount">{{ $money($invoice->total) }}</td></tr>
        <tr><td class="muted">Paid</td><td class="amount">{{ $money($invoice->paid) }}</td></tr>
        <tr class="due"><td>{{ $balance > 0 ? 'Balance due' : 'Balance' }}</td><td class="amount">{{ $money($balance) }}</td></tr>
        @if ($balance <= 0)
            <tr><td colspan="2" style="text-align: right;"><span class="stamp">PAID</span></td></tr>
        @endif
    </table>

    @if ($payments->isNotEmpty())
        <div class="section">
            <div class="heading">Payments received</div>
            <table class="payments" style="width: 60%;">
                @foreach ($payments as $payment)
                    <tr>
                        <td>{{ $payment->paid_at->format('j F Y') }}</td>
                        <td style="text-align: right;">{{ $money($payment->amount) }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    @if ($balance > 0 && $paymentDetails !== '')
        <div class="box">
            <div class="heading">How to pay</div>
            <div>{!! nl2br(e($paymentDetails)) !!}</div>
            <div class="muted" style="margin-top: 6px;">Please quote {{ $invoice->number }} with your payment.</div>
        </div>
    @endif

    @if ($invoice->note)
        <div class="section">{!! nl2br(e($invoice->note)) !!}</div>
    @endif

    <div class="footer">{{ $legalName }} · Invoice {{ $invoice->number }}</div>
</body>
</html>
