<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $r['number'] }}</title>
    <style>
        @page { margin: 28px 34px; }
        body { font-family: DejaVu Sans, sans-serif; color: #1E293B; font-size: 11px; line-height: 1.45; }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #64748B; }
        .small { font-size: 9px; }
        .head td { vertical-align: top; }
        .title { font-size: 20px; font-weight: bold; letter-spacing: 2px; color: #0F172A; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 9px; font-weight: bold; letter-spacing: 1px; }
        .badge-paid { background: #ECFDF5; color: #047857; border: 1px solid #A7F3D0; }
        .badge-pending { background: #FFFBEB; color: #B45309; border: 1px solid #FDE68A; }
        .badge-failed { background: #FEF2F2; color: #B91C1C; border: 1px solid #FECACA; }
        .divider { border-top: 1px dashed #CBD5E1; margin: 14px 0; }
        .label { font-size: 9px; font-weight: bold; color: #94A3B8; text-transform: uppercase; letter-spacing: 1px; padding-bottom: 4px; }
        .kv td { padding: 2px 0; }
        .kv td.v { text-align: right; font-weight: bold; color: #0F172A; }
        .items th { background: #F1F5F9; color: #475569; font-size: 9px; text-transform: uppercase; letter-spacing: 1px; padding: 8px 10px; text-align: left; }
        .items td { padding: 9px 10px; border-bottom: 1px solid #E2E8F0; }
        .right, .items th.right { text-align: right; }
        .totals td { padding: 3px 10px; }
        .totals .grand td { border-top: 2px solid #0F172A; padding-top: 8px; font-size: 13px; font-weight: bold; color: #0F172A; }
        .stamp { margin-top: 18px; padding: 10px 12px; border-radius: 8px; background: #F8FAFC; border: 1px solid #E2E8F0; }
    </style>
</head>
<body>
<table class="head">
    <tr>
        <td style="width:55%">
            @if(!empty($logoPath))
                <img src="{{ $logoPath }}" style="max-height:36px; max-width:170px;" alt="">
            @else
                <div class="title" style="letter-spacing:0">{{ $r['merchant']['name'] }}</div>
            @endif
            @if(!empty($r['merchant']['email']))
                <div class="muted" style="margin-top:4px">{{ $r['merchant']['email'] }}</div>
            @endif
        </td>
        <td class="right">
            <div class="title">{{ $r['status'] === 'paid' ? 'BUKTI PEMBAYARAN' : 'INVOICE' }}</div>
            <div class="muted">{{ $r['number'] }}</div>
            <div style="margin-top:6px"><span class="badge badge-{{ $r['status'] }}">{{ $r['status_label'] }}</span></div>
        </td>
    </tr>
</table>

<div class="divider"></div>

<table>
    <tr>
        <td style="width:48%; vertical-align:top">
            <div class="label">Ditagihkan kepada</div>
            <div style="font-weight:bold; font-size:12px">{{ $r['customer']['name'] }}</div>
            @if($r['customer']['email'])<div class="muted">{{ $r['customer']['email'] }}</div>@endif
            @if($r['customer']['mobile'])<div class="muted">{{ $r['customer']['mobile'] }}</div>@endif
        </td>
        <td style="width:4%"></td>
        <td style="width:48%; vertical-align:top">
            <div class="label">Detail pembayaran</div>
            <table class="kv">
                <tr><td class="muted">Tanggal pesanan</td><td class="v">{{ $r['created_at']->translatedFormat('d M Y, H:i') }}</td></tr>
                @if($r['paid_at'])
                    <tr><td class="muted">Tanggal bayar</td><td class="v">{{ $r['paid_at']->translatedFormat('d M Y, H:i') }}</td></tr>
                @endif
                <tr><td class="muted">Metode</td><td class="v">{{ $r['method']['label'] }}</td></tr>
                @if($r['method']['detail'])
                    <tr><td class="muted">{{ str_contains($r['method']['label'], 'Virtual Account') ? 'Nomor VA' : 'Keterangan' }}</td><td class="v">{{ $r['method']['detail'] }}</td></tr>
                @endif
                @foreach($r['references'] as $label => $value)
                    <tr><td class="muted">{{ $label }}</td><td class="v small">{{ $value }}</td></tr>
                @endforeach
            </table>
        </td>
    </tr>
</table>

<table class="items" style="margin-top:18px">
    <thead>
    <tr><th>Deskripsi</th><th class="right">Jumlah</th></tr>
    </thead>
    <tbody>
    @foreach($r['items'] as $item)
        <tr>
            <td>{{ $item['title'] }}@if($item['type'])<br><span class="muted small">{{ $item['type'] }}</span>@endif</td>
            <td class="right">{{ handlePrice($item['amount']) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals" style="margin-top:10px">
    <tr><td style="width:55%"></td><td class="muted">Subtotal</td><td class="right">{{ handlePrice($r['subtotal']) }}</td></tr>
    @if($r['discount'] > 0)
        <tr><td></td><td class="muted">Diskon</td><td class="right">- {{ handlePrice($r['discount']) }}</td></tr>
    @endif
    @if($r['tax'] > 0)
        <tr><td></td><td class="muted">Pajak</td><td class="right">{{ handlePrice($r['tax']) }}</td></tr>
    @endif
    <tr class="grand"><td></td><td>Total {{ $r['status'] === 'paid' ? 'dibayar' : 'tagihan' }}</td><td class="right">{{ handlePrice($r['total']) }}</td></tr>
</table>

<div class="stamp small muted">
    @if($r['status'] === 'paid')
        Dokumen ini adalah bukti pembayaran yang sah dan dibuat otomatis oleh sistem {{ $r['merchant']['name'] }}.
    @else
        Invoice ini belum lunas. Bukti pembayaran akan tersedia setelah pembayaran diterima.
    @endif
    Dicetak {{ now()->setTimezone(getTimezone() ?: 'Asia/Jakarta')->translatedFormat('d M Y, H:i') }}.
</div>
</body>
</html>
