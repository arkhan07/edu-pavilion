{{-- Isi checkout Espay: dipakai di popup (keranjang & top up) dan halaman dashboard /panel/financial/espay/{order} --}}
<div class="espay-pay js-espay-pay-root"
     data-pay-url="{{ route('espay.pay', ['order' => $order->id]) }}"
     data-checkout-url="{{ route('espay.checkout', ['order' => $order->id]) }}"
     data-status-url="{{ route('espay.status', ['order' => $order->id]) }}"
     data-waiting="{{ (!empty($va) or !empty($qris)) ? 1 : 0 }}">

    <div class="espay-pay__summary">
        <div>
            <div class="espay-pay__label">
                {{ $order->is_charge_account ? 'Top up saldo' : 'Pembayaran pesanan' }} #{{ $order->id }}
                @if(!$isProduction)
                    <span class="espay-pay__badge">SANDBOX</span>
                @endif
            </div>
            <div class="espay-pay__amount">Rp {{ number_format($amount, 0, ',', '.') }}</div>
        </div>
        <img src="/assets/default/img/espay/espay-secure.svg" alt="Pembayaran aman via Espay" class="espay-pay__secure">
    </div>

    @if(!empty($va))
        <div class="espay-pay__box">
            <div class="d-flex align-items-center">
                <img src="{{ $va['logo'] }}" alt="{{ $va['bank_name'] }}" class="espay-pay__box-logo">
                <div class="ml-10 text-left">
                    <div class="espay-pay__box-title">{{ $va['bank_name'] }} Virtual Account</div>
                    <div class="espay-pay__muted">Nomor VA khusus akun Anda</div>
                </div>
            </div>

            <div class="espay-pay__va">
                <span class="js-espay-va-number">{{ $va['number'] }}</span>
                <button type="button" class="espay-pay__copy js-espay-copy" data-copy="{{ $va['number'] }}">Salin</button>
            </div>

            <div class="espay-pay__row">
                <span>Nominal transfer</span>
                <strong>Rp {{ number_format($amount, 0, ',', '.') }}
                    <button type="button" class="espay-pay__copy js-espay-copy" data-copy="{{ (int) $amount }}">Salin</button>
                </strong>
            </div>

            <ol class="espay-pay__steps">
                <li>Buka m-banking, internet banking, atau ATM {{ $va['bank_name'] }} (atau bank lain lewat menu transfer antarbank).</li>
                <li>Pilih menu <strong>Virtual Account</strong>, masukkan nomor di atas.</li>
                <li>Transfer <strong>tepat Rp {{ number_format($amount, 0, ',', '.') }}</strong>. Nominal yang berbeda akan masuk sebagai saldo akun Anda.</li>
            </ol>

            <div class="espay-pay__waiting js-espay-status"><span class="espay-pay__spinner"></span> Menunggu pembayaran&hellip; halaman ini akan diperbarui otomatis.</div>
        </div>
    @elseif(!empty($qris))
        <div class="espay-pay__box text-center">
            <div class="d-flex align-items-center justify-content-center">
                <img src="/assets/default/img/espay/qris.svg" alt="QRIS" class="espay-pay__box-logo">
            </div>
            <div class="espay-pay__muted mt-5">Scan dengan aplikasi e-wallet atau m-banking apa pun yang mendukung QRIS.</div>

            @if(!empty($qris['image']))
                <img class="espay-pay__qr" src="{{ str_starts_with($qris['image'], 'data:') ? $qris['image'] : 'data:image/png;base64,' . $qris['image'] }}" alt="QRIS">
            @elseif(!empty($qris['url']))
                <img class="espay-pay__qr" src="{{ $qris['url'] }}" alt="QRIS">
            @elseif(!empty($qris['content']))
                <div class="espay-pay__qr">{!! \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')->size(240)->margin(1)->errorCorrection('M')->generate($qris['content']) !!}</div>
            @endif

            @if(!$isProduction and (!empty($qris['content']) or !empty($qris['url'])))
                <div class="espay-pay__sandbox">
                    <div class="espay-pay__sandbox-title">Mode sandbox: data untuk simulasi pembayaran</div>
                    @php
                        parse_str((string) parse_url($qris['url'] ?? '', PHP_URL_QUERY), $qrisQuery);
                        $qrisTrxId = $qrisQuery['trx_id'] ?? null;
                    @endphp
                    @if($qrisTrxId)
                        <div class="espay-pay__row"><span>ID transaksi</span><strong>{{ $qrisTrxId }} <button type="button" class="espay-pay__copy js-espay-copy" data-copy="{{ $qrisTrxId }}">Salin</button></strong></div>
                    @endif
                    @if(!empty($qris['content']))
                        <div class="espay-pay__row"><span>Kode QR (teks)</span><strong><button type="button" class="espay-pay__copy js-espay-copy" data-copy="{{ $qris['content'] }}">Salin</button></strong></div>
                    @endif
                    @if(!empty($qris['url']))
                        <a href="{{ $qris['url'] }}" target="_blank" rel="noopener" class="espay-pay__sandbox-link">Buka QR di Espay &rarr;</a>
                    @endif
                </div>
            @endif

            @if(!empty($qris['valid_up_to']))
                <div class="espay-pay__muted">Berlaku sampai {{ \Carbon\Carbon::parse($qris['valid_up_to'])->format('d M Y H:i') }} WIB</div>
            @endif
            <div class="espay-pay__waiting js-espay-status"><span class="espay-pay__spinner"></span> Menunggu pembayaran&hellip;</div>
        </div>
    @endif

    <div class="espay-pay__error js-espay-error {{ empty($errorMessage) ? 'd-none' : '' }}">{{ $errorMessage ?? '' }}</div>

    @if(!empty($methods['va']) or !empty($methods['qris']))
        @if(!empty($va) or !empty($qris))
            <button type="button" class="espay-pay__switch js-espay-toggle-methods">Ganti metode pembayaran</button>
        @endif

        <div class="espay-pay__methods js-espay-methods {{ (!empty($va) or !empty($qris)) ? 'd-none' : '' }}">
            @if(!empty($methods['qris']))
                <div class="espay-pay__group">QRIS</div>
                @include('web.default.cart.channels.espay_method', ['method' => $methods['qris'], 'selected' => $selectedProduct === $methods['qris']['code']])
            @endif

            @if(!empty($methods['va']))
                <div class="espay-pay__group">Transfer Virtual Account</div>
                @foreach($methods['va'] as $method)
                    @include('web.default.cart.channels.espay_method', ['method' => $method, 'selected' => $selectedProduct === $method['code']])
                @endforeach
            @endif
        </div>
    @else
        <div class="alert alert-warning mt-15 mb-0">
            Daftar metode pembayaran Espay belum dapat dimuat. Silakan coba beberapa saat lagi.
        </div>
    @endif

    <div class="espay-pay__footer">
        <span class="espay-pay__muted">Diproses aman oleh Espay</span>
        @if(!empty($va) or !empty($qris))
            <a href="{{ $doneUrl }}" class="espay-pay__done">Saya sudah membayar</a>
        @endif
    </div>
</div>
