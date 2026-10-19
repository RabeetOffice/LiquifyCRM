@php
    use App\Modules\Sales\Support\Money;

    $money = fn ($amount): string => Money::format((float) $amount, $invoice->currency);
    $bill = $invoice->bill_to;
    $balance = $invoice->balance();
    $contact = array_values(array_filter([$company['phone'] ?? '', $company['email'] ?? '', $website]));
    $registered = array_values(array_filter([
        ($company['registration_number'] ?? '') !== '' ? 'Company no. '.$company['registration_number'] : '',
        ($company['tax_number'] ?? '') !== '' ? 'VAT no. '.$company['tax_number'] : '',
    ]));
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->number }}</title>
    {{-- DOMPDF draws tables and plain CSS (no flex, no grid). DejaVu Sans prints every name and the £, € and $ signs. --}}
    <style>
        @page { margin: 0; }
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { margin: 0; padding: 0; color: #292524; font-size: 9px; line-height: 1.55; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; padding: 0; }
        .page { padding: 0 46px; }
        .muted { color: #78716c; }
        .strong { font-weight: bold; color: #1c1917; }

        /* The band across the top in the brand's colour. */
        .band { height: 8px; background: {{ $accent }}; }

        .head { margin-top: 34px; }
        .logo-wide { max-height: 54px; max-width: 230px; }
        .logo-square { height: 58px; width: 58px; }
        .tile { width: 54px; height: 54px; padding: 0; background: {{ $accent }}; color: #ffffff; font-size: 19px; font-weight: bold; text-align: center; vertical-align: middle; line-height: 1; }
        .brand-name { font-size: 13px; font-weight: bold; color: #1c1917; margin-top: 10px; }
        .title { font-size: 28px; font-weight: bold; letter-spacing: 3px; color: {{ $accent }}; text-align: right; line-height: 1.1; }
        .number { font-size: 10.5px; font-weight: bold; color: #1c1917; text-align: right; margin-top: 6px; }
        .status { text-align: right; margin-top: 8px; }
        .pill { display: inline-block; padding: 3px 10px; font-size: 8.5px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; color: {{ $status['color'] }}; background: {{ $status['tint'] }}; border: 1px solid {{ $status['color'] }}; }

        /* From, bill to and the dates, side by side on a soft tint. */
        .facts { margin-top: 28px; background: {{ $tint }}; }
        .facts td { padding: 16px 18px; width: 33.3%; }
        .label { font-size: 7.5px; font-weight: bold; letter-spacing: 1.2px; text-transform: uppercase; color: {{ $accent }}; margin-bottom: 6px; }
        .name { font-size: 10.5px; font-weight: bold; color: #1c1917; margin-bottom: 2px; }
        .dates td { padding: 1px 0; width: auto; }
        .dates .key { color: #78716c; }
        .dates .val { text-align: right; font-weight: bold; color: #1c1917; white-space: nowrap; }
        .due-now { margin-top: 8px; padding-top: 8px; border-top: 1px solid #d6d3d1; }
        .due-now .val { font-size: 12px; color: {{ $balance > 0 ? $accent : '#15803D' }}; }

        /* What was bought. */
        .lines { margin-top: 26px; }
        .lines th { font-size: 7.5px; font-weight: bold; letter-spacing: 1.2px; text-transform: uppercase; color: #78716c; text-align: left; padding: 0 0 8px; border-bottom: 1.5px solid #1c1917; }
        .lines td { padding: 12px 0; border-bottom: 1px solid #e7e5e4; }
        .lines .num { width: 26px; color: #a8a29e; }
        .lines .amount { text-align: right; white-space: nowrap; width: 120px; }
        .item { font-size: 10px; font-weight: bold; color: #1c1917; }

        /* The sums, on the right. */
        .sums { width: 270px; margin: 16px 0 0 auto; }
        .sums td { padding: 5px 0; }
        .sums .amount { text-align: right; white-space: nowrap; }
        .sums .total td { font-size: 11px; font-weight: bold; color: #1c1917; border-bottom: 1px solid #e7e5e4; padding-bottom: 8px; }
        .balance td { padding: 9px 12px; font-size: 12px; line-height: 1.3; font-weight: bold; color: #ffffff; vertical-align: middle; background: {{ $balance > 0 ? $accent : '#15803D' }}; }

        .section { margin-top: 26px; }
        .heading { font-size: 7.5px; font-weight: bold; letter-spacing: 1.2px; text-transform: uppercase; color: #78716c; margin-bottom: 6px; }
        .paid-list td { padding: 3px 0; border-bottom: 1px dotted #e7e5e4; }
        .paid-list .amount { text-align: right; white-space: nowrap; }

        .pay { margin-top: 26px; border: 1px solid #e7e5e4; }
        .pay td { padding: 14px 16px; }
        .pay .bar { width: 4px; padding: 0; background: {{ $accent }}; }
        .ref { margin-top: 8px; color: #78716c; }

        .note { margin-top: 22px; color: #57534e; }

        .footer { position: fixed; bottom: 0; left: 0; right: 0; padding: 12px 46px 18px; border-top: 1px solid #e7e5e4; font-size: 7.5px; color: #a8a29e; }
        .footer td { vertical-align: middle; }
    </style>
</head>
<body>
    <div class="band"></div>

    <div class="page">
        {{-- Who sends it, and what it is. --}}
        <table class="head">
            <tr>
                <td style="width: 60%;">
                    @if ($logo)
                        <img src="{{ $logo['src'] }}" class="{{ $logo['wide'] ? 'logo-wide' : 'logo-square' }}" alt="">
                    @else
                        <table style="width: auto;"><tr><td class="tile">{{ $initials }}</td></tr></table>
                    @endif
                    @if (! $logo || ! $logo['wide'])
                        <div class="brand-name">{{ $legalName }}</div>
                    @endif
                </td>
                <td style="width: 40%;">
                    <div class="title">INVOICE</div>
                    <div class="number">{{ $invoice->number }}</div>
                    <div class="status"><span class="pill">{{ $status['label'] }}</span></div>
                </td>
            </tr>
        </table>

        {{-- From, bill to, dates. --}}
        <table class="facts">
            <tr>
                <td>
                    <div class="label">From</div>
                    <div class="name">{{ $legalName }}</div>
                    @if (($company['address'] ?? '') !== '')
                        <div>{!! nl2br(e($company['address'])) !!}</div>
                    @endif
                    @foreach ($contact as $line)
                        <div class="muted">{{ $line }}</div>
                    @endforeach
                </td>
                <td>
                    <div class="label">Bill to</div>
                    <div class="name">{{ $bill['name'] ?? '' }}</div>
                    @if (! empty($bill['address']))
                        <div>{!! nl2br(e($bill['address'])) !!}</div>
                    @endif
                    @if (! empty($bill['email']))
                        <div class="muted">{{ $bill['email'] }}</div>
                    @endif
                    @if (! empty($bill['phone']))
                        <div class="muted">{{ $bill['phone'] }}</div>
                    @endif
                </td>
                <td>
                    <div class="label">Details</div>
                    <table class="dates">
                        <tr><td class="key">Invoice no.</td><td class="val">{{ $invoice->number }}</td></tr>
                        <tr><td class="key">Date</td><td class="val">{{ $invoice->issued_on->format('j M Y') }}</td></tr>
                        @if ($invoice->due_on)
                            <tr><td class="key">Due by</td><td class="val">{{ $invoice->due_on->format('j M Y') }}</td></tr>
                        @endif
                    </table>
                    <table class="dates due-now">
                        <tr><td class="key">Amount due</td><td class="val">{{ $money($balance) }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        {{-- What was bought. --}}
        <table class="lines">
            <thead>
                <tr><th class="num">#</th><th>Description</th><th class="amount" style="text-align: right;">Amount</th></tr>
            </thead>
            <tbody>
                @foreach ($invoice->items as $index => $item)
                    <tr>
                        <td class="num">{{ $index + 1 }}</td>
                        <td>
                            <div class="item">{{ $item['description'] }}</div>
                            @if ($invoice->sale?->closed_at)
                                <div class="muted">Agreed on {{ $invoice->sale->closed_at->format('j F Y') }}</div>
                            @endif
                        </td>
                        <td class="amount strong">{{ $money($item['amount']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- The sums. --}}
        <table class="sums">
            <tr class="total"><td>Total</td><td class="amount">{{ $money($invoice->total) }}</td></tr>
            <tr><td class="muted">Paid to date</td><td class="amount muted">{{ (float) $invoice->paid > 0 ? '− '.$money($invoice->paid) : $money(0) }}</td></tr>
            <tr><td colspan="2" style="padding: 8px 0 0;">
                <table class="balance"><tr><td>{{ $balance > 0 ? 'Balance due' : 'Paid in full' }}</td><td style="text-align: right; white-space: nowrap;">{{ $money($balance) }}</td></tr></table>
            </td></tr>
        </table>

        {{-- Money already in. --}}
        @if ($payments->isNotEmpty())
            <div class="section" style="width: 270px; margin-left: auto;">
                <div class="heading">Payments received</div>
                <table class="paid-list">
                    @foreach ($payments as $payment)
                        <tr>
                            <td>{{ $payment->paid_at->format('j M Y') }}</td>
                            <td class="amount">{{ $money($payment->amount) }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif

        {{-- How to pay the rest. --}}
        @if ($balance > 0 && $paymentDetails !== '')
            <table class="pay">
                <tr>
                    <td class="bar"></td>
                    <td>
                        <div class="heading">How to pay</div>
                        <div class="strong" style="font-weight: normal;">{!! nl2br(e($paymentDetails)) !!}</div>
                        <div class="ref">Please use <span class="strong">{{ $invoice->number }}</span> as the payment reference{{ $invoice->due_on ? ', and pay by '.$invoice->due_on->format('j F Y') : '' }}.</div>
                    </td>
                </tr>
            </table>
        @endif

        @if ($invoice->note)
            <div class="note">{!! nl2br(e($invoice->note)) !!}</div>
        @endif
    </div>

    <div class="footer">
        <table>
            <tr>
                <td>{{ $legalName }}{{ $registered !== [] ? ' · '.implode(' · ', $registered) : '' }}</td>
                <td style="text-align: right;">{{ implode(' · ', $contact) }}</td>
            </tr>
        </table>
    </div>
</body>
</html>
