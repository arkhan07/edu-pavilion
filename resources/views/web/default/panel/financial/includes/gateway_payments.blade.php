{{-- Riwayat top up & pembayaran via payment gateway: semua status (menunggu, berhasil, gagal) --}}
@if(!empty($payments) and $payments->count() > 0)
    <section class="mb-40">
        <h2 class="section-title">Riwayat Top Up &amp; Pembayaran</h2>

        <div class="panel-section-card py-20 px-25 mt-20">
            <div class="table-responsive">
                <table class="table text-center custom-table">
                    <thead>
                    <tr>
                        <th class="text-left">Transaksi</th>
                        <th class="text-left">Metode</th>
                        <th class="text-center">{{ trans('panel.amount') }} ({{ $currency }})</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">{{ trans('public.date') }}</th>
                        <th class="text-center"></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($payments as $payment)
                        @php
                            $paymentData = json_decode((string) $payment->payment_data, true) ?: [];
                            $isEspay = ($paymentData['gateway'] ?? null) === 'Espay';
                            $bankCode = $paymentData['bank_code'] ?? null;
                            $isQris = $isEspay && !empty($paymentData['product_code']) && \App\Services\Espay\EspayPaymentService::isQrisProduct($paymentData['product_code']);
                            $item = $payment->orderItems->first();
                            $itemTitle = $item?->webinar?->title ?? $item?->bundle?->title ?? $item?->product?->title ?? $item?->subscribe?->title;
                            $isWaiting = in_array($payment->status, [\App\Models\Order::$pending, \App\Models\Order::$paying]);
                        @endphp
                        <tr>
                            <td class="text-left align-middle">
                                <div class="font-14 font-weight-500 text-dark-blue">
                                    @if($payment->is_charge_account or $payment->type === \App\Models\Order::$charge)
                                        {{ trans('financial.charge_account') }}
                                    @else
                                        {{ $itemTitle ?: 'Pembelian' }}
                                        @if($payment->orderItems->count() > 1)
                                            <span class="text-gray font-12">+{{ $payment->orderItems->count() - 1 }} item</span>
                                        @endif
                                    @endif
                                </div>
                                <div class="font-12 text-gray">
                                    #{{ $payment->id }}
                                    @if(($paymentData['source'] ?? null) === 'static_va_topup')
                                        &middot; transfer langsung ke VA
                                    @endif
                                </div>
                            </td>
                            <td class="text-left align-middle">
                                <div class="d-flex align-items-center">
                                    @if($isEspay and ($isQris or $bankCode))
                                        <img src="{{ $isQris ? '/assets/default/img/espay/qris.svg' : \App\Services\Espay\EspayPaymentMethods::bankLogo($bankCode) }}" alt="" width="54" height="18" class="mr-10" style="object-fit:contain">
                                    @endif
                                    <div>
                                        <div class="font-12 font-weight-500 text-dark-blue">
                                            @if($isQris)
                                                QRIS
                                            @elseif($isEspay and $bankCode)
                                                {{ \App\Services\Espay\EspayPaymentMethods::bankName($bankCode, $paymentData['product_name'] ?? null) }} VA
                                            @else
                                                {{ $paymentData['gateway'] ?? '-' }}
                                            @endif
                                        </div>
                                        @if(!empty($paymentData['va_number']))
                                            <div class="font-12 text-gray">{{ $paymentData['va_number'] }}</div>
                                        @elseif($isEspay and empty($paymentData['product_code']))
                                            <div class="font-12 text-gray">Metode belum dipilih</div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="text-center align-middle">
                                <span class="font-16 font-weight-bold {{ $payment->status === \App\Models\Order::$paid ? 'text-primary' : 'text-gray' }}">{{ handlePrice($payment->total_amount, false) }}</span>
                            </td>
                            <td class="text-center align-middle">
                                @if($payment->status === \App\Models\Order::$paid)
                                    <span class="badge badge-pill" style="background:#ECFDF5;color:#047857;padding:6px 12px;">Berhasil</span>
                                @elseif($isWaiting)
                                    <span class="badge badge-pill" style="background:#FFFBEB;color:#B45309;padding:6px 12px;">Menunggu pembayaran</span>
                                @else
                                    <span class="badge badge-pill" style="background:#FEF2F2;color:#B91C1C;padding:6px 12px;">Gagal / kedaluwarsa</span>
                                @endif
                            </td>
                            <td class="text-center align-middle">
                                <span class="font-12">{{ dateTimeFormat($payment->created_at, 'j M Y H:i') }}</span>
                            </td>
                            <td class="text-center align-middle">
                                @if($isWaiting and $isEspay)
                                    <button type="button" class="btn btn-sm btn-primary js-espay-open" data-url="{{ route('espay.checkout', ['order' => $payment->id]) }}">Bayar</button>
                                @else
                                    <a href="/panel/financial/payment-status?order_id={{ $payment->id }}" class="btn btn-sm btn-border-gray300">Detail</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-20">
            {{ $payments->appends(request()->except('payments_page'))->links('vendor.pagination.panel') }}
        </div>
    </section>

    @include('web.default.cart.channels.espay_modal')

    @push('scripts_bottom')
        <script>
            jQuery(document).on('click', '.js-espay-open', function () {
                window.EspayCheckout.open(jQuery(this).data('url'));
            });
        </script>
    @endpush
@endif
