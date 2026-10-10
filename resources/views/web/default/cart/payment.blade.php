@extends(getTemplate() . '.layouts.app')

@push('styles_top')
    <style>
        .flip-badge {
            background: linear-gradient(135deg, #FF6B35 0%, #F97316 100%);
            color: #fff;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.8px;
            padding: 4px 10px;
            border-radius: 20px;
            position: absolute;
            top: 12px;
            right: 12px;
            box-shadow: 0 2px 8px rgba(255, 107, 53, 0.3);
        }

        .flip-badge-production {
            background: linear-gradient(135deg, #10B981 0%, #059669 100%);
            font-size: 9px;
            padding: 3px 8px;
            top: 12px;
            right: 12px;
            letter-spacing: 1px;
        }

        .flip-methods {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            justify-content: center;
            margin-top: 10px;
        }

        .flip-method-pill {
            font-size: 10px;
            font-weight: 600;
            color: #475569;
            background: #F1F5F9;
            border: 1px solid #E2E8F0;
            padding: 3px 8px;
            border-radius: 20px;
        }

        /* === STATE JELAS: belum pilih vs terpilih === */
        .charge-account-radio label {
            transition: all 0.2s ease;
            cursor: pointer;
            border: 2px solid #E2E8F0 !important;
            background: #fff !important;
            position: relative;
            overflow: hidden;
        }
        /* Belum dipilih — abu, tidak mencolok */
        .charge-account-radio label {
            border-color: #E2E8F0 !important;
        }
        /* Hover belum dipilih — hanya sedikit gelap, tetap abu */
        .charge-account-radio label:hover {
            border-color: #CBD5E1 !important;
            background: #F8FAFC !important;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        /* TERPILIH — satu warna untuk semua: HIJAU tegas + ceklis + label Dipilih */
        .charge-account-radio input:checked+label {
            border-color: #10B981 !important;
            background: #ECFDF5 !important;
            box-shadow: 0 8px 28px rgba(16,185,129,0.22) !important;
        }
        /* Garis atas hijau hanya saat terpilih */
        .charge-account-radio label::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: #10B981;
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        .charge-account-radio input:checked+label::before {
            opacity: 1;
        }
        /* Ceklis bulat hijau di pojok kanan atas saat terpilih */
        .charge-account-radio input:checked+label::after {
            content: '✓';
            position: absolute;
            top: 10px;
            right: 10px;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: #10B981;
            color: #fff;
            font-size: 14px;
            font-weight: 800;
            line-height: 26px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(16,185,129,0.4);
        }
        /* Tulisan status di bawah kartu */
        .pay-status {
            margin-top: 10px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.5px;
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-block;
        }
        .pay-status-unselected {
            color: #94A3B8;
            background: #F1F5F9;
            border: 1px solid #E2E8F0;
        }
        .charge-account-radio input:checked+label .pay-status-unselected {
            display: none;
        }
        .pay-status-selected {
            display: none;
            color: #fff;
            background: #10B981;
        }
        .charge-account-radio input:checked+label .pay-status-selected {
            display: inline-block;
        }
        /* Hover pada yang sudah terpilih — tetap hijau, tidak balik abu */
        .charge-account-radio input:checked+label:hover {
            border-color: #10B981 !important;
            background: #ECFDF5 !important;
        }
        /* Flip di bawah minimum — tetap bisa diklik, tapi ada warning */
        .flip-below-min label {
            border-style: dashed !important;
        }

        .flip-trust {
            font-size: 11px;
            color: #64748B;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 4px;
            justify-content: center;
        }
    </style>
@endpush

@section('content')
    <section class="cart-banner position-relative text-center">
        <h1 class="font-30 text-white font-weight-bold">{{ trans('cart.checkout') }}</h1>
        <span
            class="payment-hint font-20 text-white d-block">{{ handlePrice($total) . ' ' . trans('cart.for_items', ['count' => $count]) }}</span>
    </section>

    <section class="container mt-45">

        @if(!empty($totalCashbackAmount))
            <div class="d-flex align-items-center mb-25 p-15 success-transparent-alert">
                <div class="success-transparent-alert__icon d-flex align-items-center justify-content-center">
                    <i data-feather="credit-card" width="18" height="18" class=""></i>
                </div>

                <div class="ml-10">
                    <div class="font-14 font-weight-bold ">{{ trans('update.get_cashback') }}</div>
                    <div class="font-12 ">
                        {{ trans('update.by_purchasing_this_cart_you_will_get_amount_as_cashback', ['amount' => handlePrice($totalCashbackAmount)]) }}
                    </div>
                </div>
            </div>
        @endif

        @php
            $isMultiCurrency = !empty(getFinancialCurrencySettings('multi_currency'));
            $userCurrency = currency();
            $invalidChannels = [];
        @endphp

        <h2 class="section-title">{{ trans('financial.select_a_payment_gateway') }}</h2>

        <form action="/payments/payment-request" method="post" class=" mt-25">
            {{ csrf_field() }}
            <input type="hidden" name="order_id" value="{{ $order->id }}">
            <input type="hidden" id="orderTotal" value="{{ $total > $userCharge }}">

            <div class="row">
                @if(!empty($paymentChannels))
                    @foreach($paymentChannels as $paymentChannel)
                        @if(!$isMultiCurrency or (!empty($paymentChannel->currencies) and in_array($userCurrency, $paymentChannel->currencies)))
                            @php $isFlip = $paymentChannel->class_name === 'Flip'; @endphp
                            @php $flipIsProduction = $isFlip && (bool) env('FLIP_IS_PRODUCTION', false); @endphp
                            @php $flipBelowMin = $isFlip && $total < 10000; @endphp
                            <div
                                class="col-6 col-lg-4 mb-40 charge-account-radio {{ $isFlip ? 'flip-production' : '' }} {{ $flipBelowMin ? 'flip-below-min' : '' }}">
                                <input type="radio" name="gateway" id="{{ $paymentChannel->class_name }}_{{ $paymentChannel->id }}"
                                    data-class="{{ $paymentChannel->class_name }}" value="{{ $paymentChannel->id }}">
                                <label for="{{ $paymentChannel->class_name }}_{{ $paymentChannel->id }}"
                                    class="rounded-sm p-20 p-lg-45 d-flex flex-column align-items-center justify-content-center position-relative">
                                    @if($flipIsProduction)
                                    @elseif($isFlip)
                                    @endif
                                    <img src="{{ $paymentChannel->image }}" width="120" height="60" alt="{{ $paymentChannel->title }}"
                                        style="object-fit:contain;">

                                    <p class="mt-20 mt-lg-30 font-weight-500 text-dark-blue text-center" style="line-height:1.3;">
                                        {{ trans('financial.pay_via') }}
                                        <span class="font-weight-bold font-14 d-block mt-1">{{ $paymentChannel->title }}</span>
                                    </p>

                                    @if($paymentChannel->class_name === 'Espay')
                                        <span class="espay-showcase">
                                            @foreach(\App\Services\Espay\EspayPaymentMethods::showcase() as $logo)
                                                <img src="{{ $logo['logo'] }}" alt="{{ $logo['name'] }}" loading="lazy">
                                            @endforeach
                                        </span>
                                    @endif

                                    @if($flipBelowMin)
                                        <div style="margin-top:8px;font-size:11px;color:#DC2626;background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:6px 10px;text-align:center">Minimum belanja Rp10.000 tambah item agar bisa melanjutkan pembayaran</div>
                                    @endif
                                    <span class="pay-status pay-status-unselected">Pilih</span>
                                    <span class="pay-status pay-status-selected">✓ Dipilih</span>
                                </label>
                            </div>
                        @else
                            @php
                                $invalidChannels[] = $paymentChannel;
                            @endphp
                        @endif
                    @endforeach
                @endif

                <div class="col-6 col-lg-4 mb-40 charge-account-radio">
                    <input type="radio" name="gateway" id="offline" value="credit">
                    <label for="offline"
                        class="rounded-sm p-20 p-lg-45 d-flex flex-column align-items-center justify-content-center position-relative">
                        <img src="/assets/default/img/activity/pay.svg" width="120" height="60" alt="">
                        <p class="mt-30 mt-lg-50 font-weight-500 text-dark-blue">
                            {{ trans('financial.charge') }}
                            <span class="font-weight-bold">{{ trans('financial.account') }}</span>
                        </p>
                        <span class="mt-5">{{ handlePrice($userCharge) }}</span>
                        <span class="pay-status pay-status-unselected">Pilih</span>
                        <span class="pay-status pay-status-selected">✓ Dipilih</span>
                    </label>
                </div>
            </div>

            @if(!empty($invalidChannels))
                <div class="d-flex align-items-center mt-30 rounded-lg border p-15">
                    <div class="size-40 d-flex-center rounded-circle bg-gray200">
                        <i data-feather="info" class="text-gray" width="20" height="20"></i>
                    </div>
                    <div class="ml-5">
                        <h4 class="font-14 font-weight-bold text-gray">{{ trans('update.disabled_payment_gateways') }}</h4>
                        <p class="font-12 text-gray">{{ trans('update.disabled_payment_gateways_hint') }}</p>
                    </div>
                </div>

                <div class="row mt-20">
                    @foreach($invalidChannels as $invalidChannel)
                        <div class="col-6 col-lg-4 mb-40 charge-account-radio">
                            <div
                                class="disabled-payment-channel bg-white border rounded-sm p-20 p-lg-45 d-flex flex-column align-items-center justify-content-center">
                                <img src="{{ $invalidChannel->image }}" width="120" height="60" alt="">

                                <p class="mt-30 mt-lg-50 font-weight-500 text-dark-blue">
                                    {{ trans('financial.pay_via') }}
                                    <span class="font-weight-bold font-14">{{ $invalidChannel->title }}</span>
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif



            {{-- banner hanya bila channel Flip memang ditawarkan (biaya Flip tidak berlaku untuk Espay) --}}
            @if(!empty($flipServiceFeePercent) && $flipServiceFeePercent > 0 && collect($paymentChannels ?? [])->contains('class_name', 'Flip'))
                <div id="flipFeeInfo" class="d-flex align-items-center mt-20" style="border-radius:12px;background:#F59E0B;color:#fff;padding:14px 18px;">
                    <i data-feather="info" width="18" height="18" class="mr-10" style="color:#fff;flex-shrink:0;"></i>
                    <div class="font-12" style="color:#fff;">
                        <span class="font-weight-bold" style="color:#fff;">Biaya Layanan Flip {{ $flipServiceFeePercent }}%:</span>
                        <span id="flipFeeAmount" style="color:#fff;">{{ handlePrice($flipServiceFee) }}</span>
                        <span style="color:#fff;opacity:0.95;"> — akan ditambahkan ke total jika memilih Flip. Total dengan Flip: <b id="flipTotalAmount" style="color:#fff;">{{ handlePrice($totalWithFlipFee) }}</b></span>
                    </div>
                </div>
            @endif

            <div class="d-flex align-items-center justify-content-between mt-45">
                <span class="font-16 font-weight-500 text-gray">{{ trans('financial.total_amount') }}
                    <span id="displayTotal">{{ handlePrice($total) }}</span>
                    <span id="displayTotalFlip" class="d-none text-warning font-weight-bold">{{ handlePrice($totalWithFlipFee ?? $total) }} <small class="font-11">(dengan Flip)</small></span>
                    @if($total > $userCharge)
                        <br>
                        <i id="topupHint"
                            class="text-danger font-10 d-none">*{{ trans('financial.topup_account_charge_hint') }}</i>
                    @endif
                </span>
                <button type="button" id="paymentSubmit" disabled
                    class="btn btn-sm btn-primary">{{ trans('public.start_payment') }}</button>
                @if($total > $userCharge)
                    <a href="/panel/financial/account" id="topupButton"
                        class="btn-sm btn-danger d-none">{{ trans('financial.charge_account') }}</a>
                @endif
            </div>
        </form>

        @if(!empty($razorpay) and $razorpay)
            <form action="/payments/verify/Razorpay" method="get">
                <input type="hidden" name="order_id" value="{{ $order->id }}">

                <script src="https://checkout.razorpay.com/v1/checkout.js" data-key="{{ env('RAZORPAY_API_KEY') }}"
                    data-amount="{{ (int) ($order->total_amount * 100) }}" data-buttontext="product_price"
                    data-description="Rozerpay" data-currency="{{ currency() }}" data-image="{{ $generalSettings['logo'] }}"
                    data-prefill.name="{{ $order->user->full_name }}" data-prefill.email="{{ $order->user->email }}"
                    data-theme.color="#43d477">
                    </script>
            </form>
        @endif
    </section>

    @include('web.default.cart.channels.espay_modal')
@endsection

@push('scripts_bottom')
    <script src="/assets/default/js/parts/payment.min.js"></script>
    <script>
        (function ($) {
            var $form = $('form[action="/payments/payment-request"]');

            function showAttentionAlert(message, title) {
                title = title || 'Perhatian';
                if (typeof Swal !== 'undefined' && Swal.fire) {
                    Swal.fire({
                        icon: 'warning',
                        title: title,
                        text: message,
                        confirmButtonText: 'Mengerti',
                        confirmButtonColor: '#F59E0B',
                        customClass: {
                            popup: 'rounded-3 shadow-lg',
                            confirmButton: 'btn btn-warning px-4 font-weight-bold'
                        },
                        buttonsStyling: true,
                        backdrop: true,
                        allowOutsideClick: false
                    });
                } else {
                    alert(message);
                }
            }

            // Toggle total display when Flip selected
            var flipPercent = {{ $flipServiceFeePercent ?? 0 }};
            $('body').on('change', 'input[name="gateway"]', function(){
                var gw = $('input[name="gateway"]:checked').attr('data-class');
                if(gw === 'Flip' && flipPercent > 0){
                    $('#displayTotal').addClass('d-none');
                    $('#displayTotalFlip').removeClass('d-none');
                    $('#flipFeeInfo').css('background', '#10B981');
                } else {
                    $('#displayTotal').removeClass('d-none');
                    $('#displayTotalFlip').addClass('d-none');
                    $('#flipFeeInfo').css('background', '#F59E0B');
                }
            });

            // Override handler dari payment.min.js -> langsung redirect ke Flip, tanpa popup
            $('body').off('click', '#paymentSubmit');
            $('body').on('click', '#paymentSubmit', function (e) {
                e.preventDefault();
                e.stopImmediatePropagation();
                var $checked = $('input[name="gateway"]:checked');
                if (!$checked.length) {
                    showAttentionAlert('Silakan pilih metode pembayaran terlebih dahulu untuk melanjutkan.', 'Perhatian');
                    return;
                }
                var gw = $checked.attr('data-class');
                var gwVal = $checked.val();

                // Validasi minimum Flip Rp 10.000 di frontend juga
                if (gw === 'Flip') {
                    var totalText = '{{ $total }}';
                    var totalNum = parseInt(totalText.replace(/[^0-9]/g, ''), 10) || 0;
                    // fallback dari variabel PHP total
                    @if($total < 10000)
                        showAttentionAlert('Nominal minimum pembayaran adalah Rp 10.000. Silakan tambah item atau pilih metode lain untuk melanjutkan.', 'Perhatian');
                        return;
                    @endif
                }

                if (gw === 'Razorpay') {
                    $('.razorpay-payment-button').trigger('click');
                    return;
                }
                if (gwVal === 'credit') {
                    $form[0].submit();
                    return;
                }
                var $btn = $(this);
                $btn.addClass('loadingbar primary').prop('disabled', true).text('Memproses…');
                $.ajax({
                    url: $form.attr('action'),
                    method: 'POST',
                    data: $form.serialize(),
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    success: function (res) {
                        if (window.EspayCheckout && window.EspayCheckout.handlePaymentResponse(res)) {
                            $btn.removeClass('loadingbar primary').prop('disabled', false).text('{{ trans('public.start_payment') }}');
                            return;
                        }
                        if (res && res.redirect_url) {
                            window.location.href = res.redirect_url;
                        } else if (typeof res === 'string' && res.indexOf('http') === 0) {
                            window.location.href = res;
                        } else {
                            $form[0].submit();
                        }
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && (xhr.responseJSON.message || xhr.responseJSON.msg)) || 'Gagal memproses pembayaran. Silakan coba lagi.';
                        // Deteksi pesan minimum agar judul tetap Perhatian
                        var isMin = msg.toLowerCase().indexOf('minimum') !== -1 || msg.indexOf('10.000') !== -1;
                        showAttentionAlert(msg, isMin ? 'Perhatian' : 'Gagal Memproses');
                        $btn.removeClass('loadingbar primary').prop('disabled', false).text('{{ trans('public.start_payment') }}');
                    }
                });
            });
        })(jQuery);
    </script>
@endpush