@extends(getTemplate() .'.panel.layouts.panel_layout')

@push('styles_top')
    <style>
        .receipt-page { max-width: 780px; margin: 0 auto; }
        .receipt-hero { text-align: center; padding: 34px 20px 26px; border-radius: 20px; color: #fff; position: relative; overflow: hidden; }
        .receipt-hero--paid { background: linear-gradient(135deg, #10B981 0%, #059669 100%); }
        .receipt-hero--pending { background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%); }
        .receipt-hero--failed { background: linear-gradient(135deg, #EF4444 0%, #B91C1C 100%); }
        .receipt-hero::after { content: ''; position: absolute; width: 260px; height: 260px; border-radius: 50%; background: rgba(255,255,255,.08); right: -80px; top: -110px; }
        .receipt-hero__icon { width: 72px; height: 72px; margin: 0 auto 14px; border-radius: 50%; background: rgba(255,255,255,.2); display: flex; align-items: center; justify-content: center; }
        .receipt-hero__icon span { width: 52px; height: 52px; border-radius: 50%; background: #fff; display: flex; align-items: center; justify-content: center; font-size: 28px; font-weight: 800; }
        .receipt-hero--paid .receipt-hero__icon span { color: #059669; }
        .receipt-hero--pending .receipt-hero__icon span { color: #D97706; }
        .receipt-hero--failed .receipt-hero__icon span { color: #B91C1C; }
        .receipt-hero h1 { color: #fff; font-size: 24px; font-weight: 800; margin: 0; }
        .receipt-hero p { color: rgba(255,255,255,.92); font-size: 14px; margin: 6px 0 0; }
        .receipt-hero__amount { font-size: 32px; font-weight: 800; margin-top: 12px; letter-spacing: .3px; }

        .receipt-card { background: #fff; border: 1px solid #E2E8F0; border-radius: 18px; margin-top: -14px; position: relative; z-index: 1; box-shadow: 0 10px 30px rgba(15,23,42,.06); }
        .receipt-card__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding: 24px 26px 18px; border-bottom: 1px dashed #E2E8F0; flex-wrap: wrap; }
        .receipt-card__logo { max-height: 38px; max-width: 170px; }
        .receipt-card__title { text-align: right; }
        .receipt-card__title h2 { font-size: 20px; font-weight: 800; letter-spacing: 2px; color: #0F172A; margin: 0; }
        .receipt-card__number { font-size: 13px; color: #64748B; margin-top: 2px; }
        .receipt-badge { display: inline-block; margin-top: 8px; padding: 4px 12px; border-radius: 999px; font-size: 11px; font-weight: 800; letter-spacing: .8px; }
        .receipt-badge--paid { background: #ECFDF5; color: #047857; border: 1px solid #A7F3D0; }
        .receipt-badge--pending { background: #FFFBEB; color: #B45309; border: 1px solid #FDE68A; }
        .receipt-badge--failed { background: #FEF2F2; color: #B91C1C; border: 1px solid #FECACA; }

        .receipt-info { display: flex; gap: 20px; padding: 18px 26px; flex-wrap: wrap; }
        .receipt-info > div { flex: 1 1 240px; }
        .receipt-label { font-size: 11px; font-weight: 700; color: #94A3B8; text-transform: uppercase; letter-spacing: .7px; margin-bottom: 6px; }
        .receipt-row { display: flex; justify-content: space-between; gap: 12px; font-size: 13px; padding: 3px 0; color: #334155; }
        .receipt-row span:first-child { color: #64748B; }
        .receipt-row strong { color: #0F172A; text-align: right; word-break: break-all; }
        .receipt-method { display: flex; align-items: center; gap: 8px; justify-content: flex-end; }
        .receipt-method img { height: 18px; width: auto; }

        .receipt-items { width: 100%; border-collapse: collapse; margin: 4px 0 0; }
        .receipt-items th { background: #F8FAFC; color: #64748B; font-size: 11px; text-transform: uppercase; letter-spacing: .7px; padding: 10px 26px; text-align: left; border-top: 1px solid #F1F5F9; border-bottom: 1px solid #F1F5F9; }
        .receipt-items td { padding: 12px 26px; font-size: 14px; color: #0F172A; border-bottom: 1px solid #F1F5F9; vertical-align: top; }
        .receipt-items .text-right { text-align: right; }
        .receipt-items small { display: block; color: #94A3B8; font-size: 12px; }

        .receipt-totals { padding: 14px 26px 6px; margin-left: auto; max-width: 340px; }
        .receipt-totals .receipt-row strong { min-width: 110px; }
        .receipt-totals .receipt-total { border-top: 2px solid #0F172A; margin-top: 8px; padding-top: 10px; font-size: 16px; }
        .receipt-totals .receipt-total span, .receipt-totals .receipt-total strong { color: #0F172A; font-weight: 800; }

        .receipt-foot { padding: 16px 26px 22px; font-size: 12px; color: #94A3B8; border-top: 1px dashed #E2E8F0; margin-top: 12px; }
        .receipt-actions { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; margin: 22px 0 10px; }
        .receipt-actions .btn { border-radius: 10px; padding: 9px 18px; font-weight: 600; }

        @media (max-width: 575px) {
            .receipt-card__title { text-align: left; }
            .receipt-items th, .receipt-items td, .receipt-info, .receipt-card__head, .receipt-foot { padding-left: 16px; padding-right: 16px; }
            .receipt-totals { padding: 14px 16px 6px; }
            .receipt-hero__amount { font-size: 26px; }
        }

        @media print {
            body * { visibility: hidden !important; }
            .js-receipt, .js-receipt * { visibility: visible !important; }
            .js-receipt { position: absolute; left: 0; top: 0; width: 100%; margin: 0; box-shadow: none; border: 0; }
        }
    </style>
@endpush

@section('content')
    @php
        $r = $receipt;
        $isEspay = \App\Services\Espay\EspayPaymentService::isEspayOrder($r['order']);
    @endphp

    <div class="receipt-page">
        <div class="receipt-hero receipt-hero--{{ $r['status'] }}">
            <div class="receipt-hero__icon"><span>{!! ['paid' => '&#10003;', 'pending' => '&#8987;', 'failed' => '&#10005;'][$r['status']] !!}</span></div>

            @if($r['status'] === 'paid')
                <h1>Pembayaran Berhasil</h1>
                <p>Terima kasih, {{ $r['customer']['name'] }}! Pembayaran Anda sudah kami terima.</p>
                <div class="receipt-hero__amount">{{ handlePrice($r['total']) }}</div>
                <p>{{ $r['is_topup'] ? 'Saldo akun Anda sudah bertambah.' : 'Pesanan Anda sudah aktif dan bisa langsung diakses.' }}</p>
            @elseif($r['status'] === 'pending')
                <h1>Menunggu Pembayaran</h1>
                <p>Selesaikan pembayaran agar pesanan diproses. Halaman ini diperbarui otomatis.</p>
                <div class="receipt-hero__amount">{{ handlePrice($r['total']) }}</div>
            @else
                <h1>Pembayaran Gagal</h1>
                <p>Transaksi ini gagal atau sudah kedaluwarsa. Silakan buat pembayaran baru.</p>
                <div class="receipt-hero__amount">{{ handlePrice($r['total']) }}</div>
            @endif
        </div>

        <div class="receipt-card js-receipt">
            <div class="receipt-card__head">
                <div>
                    @if(!empty($r['merchant']['logo']))
                        <img src="{{ $r['merchant']['logo'] }}" alt="{{ $r['merchant']['name'] }}" class="receipt-card__logo">
                    @else
                        <strong class="font-18">{{ $r['merchant']['name'] }}</strong>
                    @endif
                    @if(!empty($r['merchant']['email']))
                        <div class="font-12 text-gray mt-5">{{ $r['merchant']['email'] }}</div>
                    @endif
                </div>
                <div class="receipt-card__title">
                    <h2>{{ $r['status'] === 'paid' ? 'BUKTI PEMBAYARAN' : 'INVOICE' }}</h2>
                    <div class="receipt-card__number">{{ $r['number'] }}</div>
                    <span class="receipt-badge receipt-badge--{{ $r['status'] }}">{{ $r['status_label'] }}</span>
                </div>
            </div>

            <div class="receipt-info">
                <div>
                    <div class="receipt-label">Ditagihkan kepada</div>
                    <div class="font-14 font-weight-bold text-dark">{{ $r['customer']['name'] }}</div>
                    @if($r['customer']['email'])<div class="font-13 text-gray">{{ $r['customer']['email'] }}</div>@endif
                    @if($r['customer']['mobile'])<div class="font-13 text-gray">{{ $r['customer']['mobile'] }}</div>@endif
                </div>
                <div>
                    <div class="receipt-label">Detail pembayaran</div>
                    <div class="receipt-row"><span>Tanggal pesanan</span><strong>{{ $r['created_at']->translatedFormat('d M Y, H:i') }}</strong></div>
                    @if($r['paid_at'])
                        <div class="receipt-row"><span>Tanggal bayar</span><strong>{{ $r['paid_at']->translatedFormat('d M Y, H:i') }}</strong></div>
                    @endif
                    <div class="receipt-row"><span>Metode</span>
                        <strong class="receipt-method">
                            @if($r['method']['logo'])<img src="{{ $r['method']['logo'] }}" alt="">@endif
                            {{ $r['method']['label'] }}
                        </strong>
                    </div>
                    @if($r['method']['detail'])
                        <div class="receipt-row"><span>{{ str_contains($r['method']['label'], 'Virtual Account') ? 'Nomor VA' : 'Keterangan' }}</span><strong>{{ $r['method']['detail'] }}</strong></div>
                    @endif
                    @foreach($r['references'] as $label => $value)
                        <div class="receipt-row"><span>{{ $label }}</span><strong>{{ $value }}</strong></div>
                    @endforeach
                </div>
            </div>

            <table class="receipt-items">
                <thead>
                <tr><th>Deskripsi</th><th class="text-right">Jumlah</th></tr>
                </thead>
                <tbody>
                @foreach($r['items'] as $item)
                    <tr>
                        <td>{{ $item['title'] }}@if($item['type'])<small>{{ $item['type'] }}</small>@endif</td>
                        <td class="text-right">{{ handlePrice($item['amount']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            <div class="receipt-totals">
                <div class="receipt-row"><span>Subtotal</span><strong>{{ handlePrice($r['subtotal']) }}</strong></div>
                @if($r['discount'] > 0)
                    <div class="receipt-row"><span>Diskon</span><strong>- {{ handlePrice($r['discount']) }}</strong></div>
                @endif
                @if($r['tax'] > 0)
                    <div class="receipt-row"><span>Pajak</span><strong>{{ handlePrice($r['tax']) }}</strong></div>
                @endif
                <div class="receipt-row receipt-total"><span>Total {{ $r['status'] === 'paid' ? 'dibayar' : 'tagihan' }}</span><strong>{{ handlePrice($r['total']) }}</strong></div>
            </div>

            <div class="receipt-foot">
                @if($r['status'] === 'paid')
                    Dokumen ini adalah bukti pembayaran yang sah dan dibuat otomatis oleh sistem {{ $r['merchant']['name'] }}. Simpan untuk keperluan arsip Anda.
                @else
                    Invoice ini belum lunas. Bukti pembayaran akan tersedia setelah pembayaran diterima.
                @endif
            </div>
        </div>

        <div class="receipt-actions">
            @if($r['status'] === 'pending' and $isEspay)
                <button type="button" class="btn btn-primary js-espay-open" data-url="{{ route('espay.checkout', ['order' => $r['order']->id]) }}">Bayar sekarang</button>
            @endif
            <a href="/panel/financial/invoice/{{ $r['order']->id }}/download" class="btn btn-border-gray300">Unduh PDF</a>
            <button type="button" class="btn btn-border-gray300" onclick="window.print()">Cetak</button>
            @if($r['status'] === 'paid')
                @if($r['is_topup'])
                    <a href="/panel/financial/account" class="btn btn-border-gray300">Lihat saldo</a>
                @else
                    <a href="/panel/webinars/purchases" class="btn btn-border-gray300">Kursus saya</a>
                @endif
            @endif
            <a href="/panel/financial/summary" class="btn btn-border-gray300">Riwayat transaksi</a>
        </div>
    </div>

    @if($r['status'] === 'pending' and $isEspay)
        @include('web.default.cart.channels.espay_modal')
    @endif
@endsection

@push('scripts_bottom')
    @if($r['status'] === 'pending' and $isEspay)
        <script>
            (function ($) {
                $(document).on('click', '.js-espay-open', function () {
                    window.EspayCheckout.open($(this).data('url'));
                });

                // status berubah (dibayar lewat VA / QRIS) -> muat ulang jadi halaman bukti pembayaran
                var poll = function () {
                    $.getJSON(@json(route('espay.status', ['order' => $r['order']->id])))
                        .done(function (res) {
                            if (res && ['paid', 'fail'].indexOf(res.status) !== -1) {
                                window.location.reload();
                                return;
                            }
                            setTimeout(poll, 10000);
                        })
                        .fail(function () { setTimeout(poll, 20000); });
                };
                setTimeout(poll, 10000);
            })(jQuery);
        </script>
    @endif
@endpush
