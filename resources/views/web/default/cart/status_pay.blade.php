@extends($statusLayout ?? getTemplate().'.layouts.app')


@section('content')


    @if(!empty($order) && $order->status === \App\Models\Order::$paid)
        <div class="no-result default-no-result my-50 d-flex align-items-center justify-content-center flex-column">
            <div class="no-result-logo">
                <img src="/assets/default/img/no-results/search.png" alt="">
            </div>
            <div class="d-flex align-items-center flex-column mt-30 text-center">
                <h2>{{ trans('cart.success_pay_title') }}</h2>
                <p class="mt-5 text-center">{!! trans('cart.success_pay_msg') !!}</p>
                <a href="/panel" class="btn btn-sm btn-primary mt-20">{{ trans('public.my_panel') }}</a>
            </div>
        </div>
    @endif

    @if(!empty($order) && $order->status === \App\Models\Order::$fail)
        <div class="no-result status-failed my-50 d-flex align-items-center justify-content-center flex-column">
            <div class="no-result-logo">
                <img src="/assets/default/img/no-results/failed_pay.png" alt="">
            </div>
            <div class="d-flex align-items-center flex-column mt-30 text-center">
                <h2>{{ trans('cart.failed_pay_title') }}</h2>
                <p class="mt-5 text-center">{!! nl2br(trans('cart.failed_pay_msg')) !!}</p>
                <a href="/panel" class="btn btn-sm btn-primary mt-20">{{ trans('public.my_panel') }}</a>
            </div>
        </div>
    @endif

    @if(!empty($order) && in_array($order->status, [\App\Models\Order::$pending, \App\Models\Order::$paying]))
        @php
            $expiredHours = env('FLIP_EXPIRED_HOURS', 24);
            $orderCreatedAt = $order->created_at;
            $paymentData = !empty($order->payment_data) ? json_decode($order->payment_data, true) : [];
            if (!empty($paymentData['created_at'])) {
                $orderCreatedAt = $paymentData['created_at'];
            }
            if (($paymentData['gateway'] ?? null) === 'Espay') {
                $expiredHours = config('espay.expiry_minutes', 1440) / 60;
            }
            $expiredAt = $orderCreatedAt + ($expiredHours * 3600);
            $formattedExpiredDate = date('Y-m-d H:i:s', $expiredAt);
            $refId = $paymentData['reference_id'] ?? ($paymentData['payment_id'] ?? ('REF-' . $order->id));
        @endphp
        <div class="no-result default-no-result my-50 d-flex align-items-center justify-content-center flex-column">
            <div class="no-result-logo mb-20">
                <img src="/assets/default/img/no-results/search.png" alt="">
            </div>
            <div class="d-flex align-items-center flex-column text-center w-100">
                <h2>{{ trans('cart.payment_pending_title') }}</h2>
                <p class="mt-5 text-center text-gray">{!! trans('cart.payment_pending_msg', ['gateway' => e($paymentData['gateway'] ?? 'payment gateway')]) !!}</p>
                
                {{-- Box Batas Waktu Pembayaran & Timer Countdown & Ref ID --}}
                <div class="mt-25 p-25 rounded-lg text-center bg-white panel-shadow border" style="max-width: 480px; width: 100%;">
                    <div class="text-gray font-16 font-weight-500 mb-5">Batas waktu pembayaran</div>
                    <div class="font-24 font-weight-bold mb-15" style="color: #f5a623; letter-spacing: 0.5px;">
                        {{ $formattedExpiredDate }}
                    </div>

                    <div class="py-15 px-20 rounded mb-20" style="background-color: #fff9e6; border: 1px dashed #ffe69c;">
                        <div class="font-12 text-gray mb-10 font-weight-500 text-uppercase" style="letter-spacing: 1px;">Sisa Waktu Pembayaran</div>
                        <div id="payment-countdown" class="d-flex justify-content-center align-items-center" data-expire="{{ $expiredAt }}">
                            <div class="d-flex flex-column align-items-center mx-10">
                                <span class="font-30 font-weight-bold text-dark count-hours">00</span>
                                <span class="font-12 text-gray">Jam</span>
                            </div>
                            <span class="font-24 font-weight-bold text-dark">:</span>
                            <div class="d-flex flex-column align-items-center mx-10">
                                <span class="font-30 font-weight-bold text-dark count-minutes">00</span>
                                <span class="font-12 text-gray">Menit</span>
                            </div>
                            <span class="font-24 font-weight-bold text-dark">:</span>
                            <div class="d-flex flex-column align-items-center mx-10">
                                <span class="font-30 font-weight-bold text-danger count-seconds">00</span>
                                <span class="font-12 text-gray">Detik</span>
                            </div>
                        </div>
                    </div>

                    @php $flipFee = (float)($paymentData['flip_service_fee'] ?? 0); $flipPercent = (float)($paymentData['flip_service_fee_percent'] ?? 0); @endphp
                    @if($flipFee > 0)
                    <div class="mt-15 p-15 rounded text-left" style="max-width:480px;width:100%;background:#f0fdf4;border:1px solid #bbf7d0;">
                        <div class="d-flex justify-content-between font-13"><span class="text-gray">Subtotal</span><span class="font-weight-600">{{ handlePrice($order->amount) }}</span></div>
                        @if(!empty($order->total_discount) && $order->total_discount > 0)
                        <div class="d-flex justify-content-between font-13 mt-5"><span class="text-gray">Diskon</span><span class="font-weight-600 text-success">-{{ handlePrice($order->total_discount) }}</span></div>
                        @endif
                        <div class="d-flex justify-content-between font-13 mt-5"><span class="text-gray">Biaya Layanan Flip @if($flipPercent>0) ({{ rtrim(rtrim(number_format($flipPercent,2,'.',''), '0'), '.') }}%) @endif</span><span class="font-weight-600" style="color:#059669;">{{ handlePrice($flipFee) }}</span></div>
                        <hr class="my-10" style="border-color:#bbf7d0;">
                        <div class="d-flex justify-content-between font-14 font-weight-bold"><span>Total Transfer</span><span style="color:#059669;">{{ handlePrice($order->total_amount) }}</span></div>
                        <div class="font-11 text-muted mt-5">Sudah termasuk Biaya Layanan. Di email Flip kolom Biaya Layanan akan tetap Rp0 (fee Flip dashboard), rincian ini dari sistem Edu Pavilion.</div>
                    </div>
                    @else
                    <div class="mt-10 font-13 text-gray">Total Transfer: <strong class="text-dark">{{ handlePrice($order->total_amount) }}</strong></div>
                    @endif
                    <div class="font-15 text-gray font-weight-500 mt-15">
                        Ref ID: <strong class="text-dark font-weight-bold">{{ $refId }}</strong>
                    </div>
                </div>

                <a href="/panel" class="btn btn-sm btn-primary mt-30 px-30">{{ trans('public.my_panel') }}</a>
            </div>
        </div>
    @endif
@endsection

@push('scripts_bottom')
    <script>
        (function($) {
            "use strict";
            var countdownEl = $('#payment-countdown');
            if (countdownEl.length) {
                var expireTimestamp = parseInt(countdownEl.attr('data-expire')) * 1000;
                
                var x = setInterval(function() {
                    var now = new Date().getTime();
                    var distance = expireTimestamp - now;
                    
                    if (distance < 0) {
                        clearInterval(x);
                        $('.count-hours').text('00');
                        $('.count-minutes').text('00');
                        $('.count-seconds').text('00');
                    } else {
                        var hours = Math.floor(distance / (1000 * 60 * 60));
                        var minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                        var seconds = Math.floor((distance % (1000 * 60)) / 1000);
                        
                        $('.count-hours').text(String(hours).padStart(2, '0'));
                        $('.count-minutes').text(String(minutes).padStart(2, '0'));
                        $('.count-seconds').text(String(seconds).padStart(2, '0'));
                    }
                }, 1000);
            }
        })(jQuery);
    </script>
@endpush
