@extends(getTemplate() .'.panel.layouts.panel_layout')

@push('styles_top')
    <link rel="stylesheet" href="/assets/default/vendors/daterangepicker/daterangepicker.min.css">
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
            letter-spacing: 1px;
        }
        .charge-account-radio label {
            transition: all 0.2s ease;
            cursor: pointer;
            border: 2px solid #E2E8F0 !important;
            background: #fff !important;
            position: relative;
            overflow: hidden;
        }
        .charge-account-radio label:hover {
            border-color: #CBD5E1 !important;
            background: #F8FAFC !important;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .charge-account-radio input:checked+label {
            border-color: #10B981 !important;
            background: #ECFDF5 !important;
            box-shadow: 0 8px 28px rgba(16,185,129,0.22) !important;
        }
        .charge-account-radio label::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: #10B981;
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        .charge-account-radio input:checked+label::before { opacity: 1; }
        .charge-account-radio input:checked+label::after {
            content: '✓';
            position: absolute;
            top: 10px; right: 10px;
            width: 26px; height: 26px;
            border-radius: 50%;
            background: #10B981;
            color: #fff;
            font-size: 14px; font-weight: 800;
            line-height: 26px; text-align: center;
            box-shadow: 0 2px 8px rgba(16,185,129,0.4);
        }
        .pay-status {
            margin-top: 10px;
            font-size: 11px; font-weight: 700;
            letter-spacing: 0.5px;
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-block;
        }
        .pay-status-unselected { color: #94A3B8; background: #F1F5F9; border: 1px solid #E2E8F0; }
        .charge-account-radio input:checked+label .pay-status-unselected { display: none; }
        .pay-status-selected { display: none; color: #fff; background: #10B981; }
        .charge-account-radio input:checked+label .pay-status-selected { display: inline-block; }
        .charge-account-radio input:checked+label:hover { border-color: #10B981 !important; background: #ECFDF5 !important; }
        .flip-below-min label { border-style: dashed !important; }
    </style>
@endpush

@section('content')

    {{-- Cashback Alert --}}
    @if(!empty($cashbackRules) and count($cashbackRules))
        @foreach($cashbackRules as $cashbackRule)
            <div class="d-flex align-items-center mb-20 p-15 success-transparent-alert {{ $classNames ?? '' }}">
                <div class="success-transparent-alert__icon d-flex align-items-center justify-content-center">
                    <i data-feather="credit-card" width="18" height="18" class=""></i>
                </div>

                <div class="ml-10">
                    <div class="font-14 font-weight-bold ">{{ trans('update.get_cashback') }}</div>

                    <div class="font-12 ">{{ trans('update.by_charging_your_wallet_will_get_amount_as_cashback',['amount' => ($cashbackRule->amount_type == 'percent' ? "%{$cashbackRule->amount}" : handlePrice($cashbackRule->amount))]) }}</div>
                </div>
            </div>
        @endforeach
    @endif



    @if(!empty($registrationBonusAmount))
        <div class="mb-25 d-flex align-items-center justify-content-between p-15 bg-white panel-shadow">
            <div class="d-flex align-items-center">
                <img src="/assets/default/img/icons/money.png" alt="money" width="51" height="51">

                <div class="ml-15">
                    <span class="d-block font-16 text-dark font-weight-bold">{{ trans('update.unlock_registration_bonus') }}</span>
                    <span class="d-block font-14 text-gray font-weight-500 mt-5">{{ trans('update.your_wallet_includes_amount_registration_bonus_This_amount_is_locked',['amount' => handlePrice($registrationBonusAmount)]) }}</span>
                </div>
            </div>

            <a href="/panel/marketing/registration_bonus" class="btn btn-border-gray300">{{ trans('update.view_more') }}</a>
        </div>
    @endif

    <section>
        <h2 class="section-title">{{ trans('financial.account_summary') }}</h2>

        <div class="activities-container mt-25 p-20 p-lg-35">
            <div class="row">
                <div class="col-12 col-md-4 d-flex align-items-center justify-content-center">
                    <div class="d-flex flex-column align-items-center text-center">
                        <img src="/assets/default/img/activity/36.svg" width="64" height="64" alt="">
                        <strong class="font-30 text-dark-blue font-weight-bold mt-5">{{ $accountCharge ? handlePrice($accountCharge) : 0 }}</strong>
                        <span class="font-16 text-gray font-weight-500">{{ trans('financial.account_charge') }}</span>
                    </div>
                </div>

                <div class="col-12 col-md-4 d-flex align-items-center justify-content-center mt-30 mt-md-0">
                    <div class="d-flex flex-column align-items-center text-center">
                        <img src="/assets/default/img/activity/37.svg" width="64" height="64" alt="">
                        <strong class="font-30 text-dark-blue font-weight-bold mt-5">{{ $readyPayout ? handlePrice($readyPayout) : 0 }}</strong>
                        <span class="font-16 text-gray font-weight-500">{{ trans('financial.ready_to_payout') }}</span>
                    </div>
                </div>

                <div class="col-12 col-md-4 d-flex align-items-center justify-content-center mt-30 mt-md-0">
                    <div class="d-flex flex-column align-items-center text-center">
                        <img src="/assets/default/img/activity/38.svg" width="64" height="64" alt="">
                        <strong class="font-30 text-dark-blue font-weight-bold mt-5">{{ $totalIncome ? handlePrice($totalIncome) : 0 }}</strong>
                        <span class="font-16 text-gray font-weight-500">{{ trans('financial.total_income') }}</span>
                    </div>
                </div>

            </div>
        </div>
    </section>
    @if (\Session::has('msg'))
        <div class="alert alert-warning">
            <ul>
                <li>{!! \Session::get('msg') !!}</li>
            </ul>
        </div>
    @endif

    @php
        $showOfflineFields = true;
        if ($errors->has('date') or $errors->has('referral_code') or $errors->has('account') or !empty($editOfflinePayment)) {
            $showOfflineFields = true;
        }

        $isMultiCurrency = !empty(getFinancialCurrencySettings('multi_currency'));
        $userCurrency = currency();
        $invalidChannels = [];
    @endphp

    <section class="mt-30">
        <h2 class="section-title">{{ trans('financial.select_the_payment_gateway') }}</h2>

        <form action="/panel/financial/{{ !empty($editOfflinePayment) ? 'offline-payments/'. $editOfflinePayment->id .'/update' : 'charge' }}" method="post" enctype="multipart/form-data" class="mt-25">
            {{csrf_field()}}

            @if($errors->has('gateway'))
                <div class="text-danger mb-3">{{ $errors->first('gateway') }}</div>
            @endif

            <div class="row">
                @foreach($paymentChannels as $paymentChannel)
                    @if(!$isMultiCurrency or (!empty($paymentChannel->currencies) and in_array($userCurrency, $paymentChannel->currencies)))
                        @php $isFlip = $paymentChannel->class_name === 'Flip'; @endphp
                        @php $flipIsProduction = $isFlip && (bool) env('FLIP_IS_PRODUCTION', false); @endphp
                        <div class="col-6 col-lg-3 mb-40 charge-account-radio text-center {{ $isFlip ? 'flip-production' : '' }}">
                            <input type="radio" class="online-gateway" name="gateway" id="{{ $paymentChannel->class_name }}_{{ $paymentChannel->id }}" @if(old('gateway') == $paymentChannel->class_name) checked @endif value="{{ $paymentChannel->class_name }}">
                            <label for="{{ $paymentChannel->class_name }}_{{ $paymentChannel->id }}" class="rounded-sm p-20 p-lg-45 d-flex flex-column align-items-center justify-content-center position-relative">
                                @if($flipIsProduction)
                                    <span class="flip-badge flip-badge-production">● LIVE</span>
                                @elseif($isFlip)
                                    <span class="flip-badge" style="background:#64748B;">SANDBOX</span>
                                @endif
                                <img src="{{ $paymentChannel->image }}" width="120" height="60" alt="{{ $paymentChannel->title }}" style="object-fit:contain;">
                                <p class="mt-20 font-14 font-weight-500 text-dark-blue text-center">{{ trans('financial.pay_via') }}
                                    <span class="font-weight-bold d-block mt-1">{{ $paymentChannel->title }}</span>
                                </p>
                                @if($paymentChannel->class_name === 'Espay')
                                    <span class="espay-showcase">
                                        @foreach(\App\Services\Espay\EspayPaymentMethods::showcase() as $logo)
                                            <img src="{{ $logo['logo'] }}" alt="{{ $logo['name'] }}" loading="lazy">
                                        @endforeach
                                    </span>
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

                @if(!empty(getOfflineBankSettings('offline_banks_status')))
                    <div class="col-6 col-lg-3 mb-40 charge-account-radio text-center">
                        <input type="radio" name="gateway" id="offline" value="offline" @if(old('gateway', 'offline') == 'offline' or !empty($editOfflinePayment)) checked @endif>
                        <label for="offline" class="rounded-sm p-20 p-lg-45 d-flex flex-column align-items-center justify-content-center position-relative">
                            <img src="/assets/default/img/activity/pay.svg" width="120" height="60" alt="">
                            <p class="mt-30 font-14 font-weight-500 text-dark-blue">{{ trans('financial.pay_via') }}
                                <span class="font-weight-bold">{{ trans('financial.offline') }}</span>
                            </p>
                            <span class="pay-status pay-status-unselected">Pilih</span>
                            <span class="pay-status pay-status-selected">✓ Dipilih</span>
                        </label>
                    </div>
                @endif
            </div>

            @if(!empty($invalidChannels))
                <div class="d-flex align-items-center rounded-lg border p-15">
                    <div class="size-40 d-flex-center rounded-circle bg-gray200">
                        <i data-feather="gift" class="text-gray" width="20" height="20"></i>
                    </div>
                    <div class="ml-5">
                        <h4 class="font-14 font-weight-bold text-gray">{{ trans('update.disabled_payment_gateways') }}</h4>
                        <p class="font-12 text-gray">{{ trans('update.disabled_payment_gateways_hint') }}</p>
                    </div>
                </div>

                <div class="row mt-20">
                    @foreach($invalidChannels as $invalidChannel)
                        <div class="col-6 col-lg-3 mb-40 charge-account-radio">
                            <div class="disabled-payment-channel bg-white border rounded-sm p-20 p-lg-45 d-flex flex-column align-items-center justify-content-center">
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

            <div class="">
                <h3 class="section-title mb-20">{{ trans('financial.finalize_payment') }}</h3>

                <div class="row mb-30" id="shopeeTopupUI">
                    <div class="col-12">
                        <label class="font-weight-500 font-14 text-dark-blue d-block mb-15">Pilih Nominal Top-Up</label>
                        <div class="row nominal-preset-container">
                            @php
                                $presets = [20000, 50000, 100000, 200000, 500000, 1000000];
                            @endphp
                            @foreach($presets as $val)
                                <div class="col-6 col-md-4 col-lg-2 mb-15">
                                    <div class="topup-preset-box border rounded-sm p-15 text-center cursor-pointer" data-amount="{{ $val }}">
                                        <span class="font-16 font-weight-bold text-dark-blue">Rp {{ number_format($val, 0, ',', '.') }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="row align-items-end">
                    <div class="col-12 col-lg-4 mb-25 mb-lg-0">
                        <label class="font-weight-500 font-14 text-dark-blue d-block">Atau Masukkan Nominal Lain (Min. Rp 10.000)</label>
                        <div class="input-group mt-5">
                            <div class="input-group-prepend">
                                <span class="input-group-text text-white font-16">
                                    {{ $currency }}
                                </span>
                            </div>
                            <input type="number" id="customAmountInput" name="amount" min="10000" class="form-control @error('amount') is-invalid @enderror"
                                   value="{{ !empty($editOfflinePayment) ? $editOfflinePayment->amount : old('amount') }}"
                                   placeholder="Contoh: 150000"/>
                            <div class="invalid-feedback">@error('amount') {{ $message }} @enderror</div>
                        </div>
                    </div>

                    <div class="col-12 col-lg-3 mb-25 mb-lg-0 js-offline-payment-input " style="{{ (!$showOfflineFields) ? 'display:none' : '' }}">
                        <div class="form-group">
                            <label class="input-label">{{ trans('financial.account') }}</label>
                            <select name="account" class="form-control @error('account') is-invalid @enderror">
                                <option selected disabled>{{ trans('financial.select_the_account') }}</option>

                                @foreach($offlineBanks as $offlineBank)
                                    <option value="{{ $offlineBank->id }}" @if(!empty($editOfflinePayment) and $editOfflinePayment->offline_bank_id == $offlineBank->id) selected @endif>{{ $offlineBank->title }}</option>
                                @endforeach
                            </select>

                            @error('account')
                            <div class="invalid-feedback"> {{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="col-12 col-lg-3 mb-25 mb-lg-0 js-offline-payment-input " style="{{ (!$showOfflineFields) ? 'display:none' : '' }}">
                        <div class="form-group">
                            <label for="referralCode" class="input-label">{{ trans('admin/main.referral_code') }}</label>
                            <input type="text" name="referral_code" id="referralCode" value="{{ !empty($editOfflinePayment) ? $editOfflinePayment->reference_number : old('referral_code') }}" class="form-control @error('referral_code') is-invalid @enderror"/>
                            @error('referral_code')
                            <div class="invalid-feedback"> {{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="col-12 col-lg-3 mb-25 mb-lg-0 js-offline-payment-input " style="{{ (!$showOfflineFields) ? 'display:none' : '' }}">
                        <div class="form-group">
                            <label class="input-label">{{ trans('public.date_time') }}</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text" id="dateRangeLabel">
                                        <i data-feather="calendar" width="18" height="18" class="text-white"></i>
                                    </span>
                                </div>
                                <input type="text" name="date" value="{{ !empty($editOfflinePayment) ? dateTimeFormat($editOfflinePayment->pay_date, 'Y-m-d H:i', false) : old('date') }}" class="form-control datetimepicker @error('date') is-invalid @enderror"
                                       aria-describedby="dateRangeLabel"/>
                                @error('date')
                                <div class="invalid-feedback"> {{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-lg-3 mb-25 mb-lg-0 js-offline-payment-input " style="{{ (!$showOfflineFields) ? 'display:none' : '' }}">
                        <div class="form-group">
                            <label class="input-label">{{ trans('update.attach_the_payment_photo') }}</label>

                            <label for="attachmentFile" id="attachmentFileLabel" class="custom-upload-input-group">
                                <span class="custom-upload-icon text-white">
                                    <i data-feather="upload" width="18" height="18" class="text-white"></i>
                                </span>
                                <div class="custom-upload-input"></div>
                            </label>

                            <input type="file" name="attachment" id="attachmentFile"
                                   class="form-control h-auto invisible-file-input @error('attachment') is-invalid @enderror"
                                   value=""/>
                            @error('attachment')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                            @enderror
                        </div>
                    </div>

                    <div class="col-12 col-lg-3">
                        <div class="mt-30">
                            <button type="button" id="submitChargeAccountForm" class="btn btn-primary btn-sm">{{ trans('public.pay') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </section>

    <section class="mt-40 js-bank-accounts-info" style="{{ (old('gateway') && old('gateway') !== 'offline') ? 'display:none;' : '' }}">
        <h2 class="section-title">{{ trans('financial.bank_accounts_information') }}</h2>

        <div class="row mt-25">
            @foreach($offlineBanks as $offlineBank)
                <div class="col-12 col-lg-3 mb-30 mb-lg-0">
                    <div class="py-25 px-20 rounded-sm panel-shadow d-flex flex-column align-items-center justify-content-center">
                        <img src="{{ $offlineBank->logo }}" width="120" height="60" alt="">

                        <div class="mt-15 mt-30 w-100">

                            <div class="d-flex align-items-center justify-content-between">
                                <span class="font-14 font-weight-500 text-secondary">{{ trans('public.name') }}:</span>
                                <span class="font-14 font-weight-500 text-gray">{{ $offlineBank->title }}</span>
                            </div>

                            @foreach($offlineBank->specifications as $specification)
                                <div class="d-flex align-items-center justify-content-between mt-10">
                                    <span class="font-14 font-weight-500 text-secondary">{{ $specification->name }}:</span>
                                    <span class="font-14 font-weight-500 text-gray">{{ $specification->value }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    @if($offlinePayments->count() > 0)
        <section class="mt-40">
            <h2 class="section-title">{{ trans('financial.offline_transactions_history') }}</h2>

            <div class="panel-section-card py-20 px-25 mt-20">
                <div class="row">
                    <div class="col-12 ">
                        <div class="table-responsive">
                            <table class="table text-center custom-table">
                                <thead>
                                <tr>
                                    <th>{{ trans('financial.bank') }}</th>
                                    <th>{{ trans('admin/main.referral_code') }}</th>
                                    <th class="text-center">{{ trans('panel.amount') }} ({{ $currency }})</th>
                                    <th class="text-center">{{ trans('update.attachment') }}</th>
                                    <th class="text-center">{{ trans('public.status') }}</th>
                                    <th class="text-right">{{ trans('public.controls') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($offlinePayments as $offlinePayment)
                                    <tr>
                                        <td class="text-left">
                                            <div class="d-flex flex-column">

                                                @if(!empty($offlinePayment->offlineBank))
                                                    <span class="font-weight-500 text-dark-blue">{{ $offlinePayment->offlineBank->title }}</span>
                                                @else
                                                    <span class="font-weight-500 text-dark-blue">-</span>
                                                @endif
                                                <span class="font-12 text-gray">{{ dateTimeFormat($offlinePayment->pay_date, 'j M Y H:i') }}</span>
                                            </div>
                                        </td>
                                        <td class="text-left align-middle">
                                            <span>{{ $offlinePayment->reference_number }}</span>
                                        </td>
                                        <td class="text-center align-middle">
                                            <span class="font-16 font-weight-bold text-primary">{{ handlePrice($offlinePayment->amount, false) }}</span>
                                        </td>

                                        <td class="text-center align-middle">
                                            @if(!empty($offlinePayment->attachment))
                                                <a href="{{ $offlinePayment->getAttachmentPath() }}" target="_blank" class="text-primary">{{ trans('public.view') }}</a>
                                            @else
                                                ---
                                            @endif
                                        </td>

                                        <td class="text-center align-middle">
                                            @switch($offlinePayment->status)
                                                @case(\App\Models\OfflinePayment::$waiting)
                                                    <span class="text-warning">{{ trans('public.waiting') }}</span>
                                                    @break
                                                @case(\App\Models\OfflinePayment::$approved)
                                                    <span class="text-primary">{{ trans('financial.approved') }}</span>
                                                    @break
                                                @case(\App\Models\OfflinePayment::$reject)
                                                    <span class="text-danger">{{ trans('public.rejected') }}</span>
                                                    @break
                                            @endswitch
                                        </td>
                                        <td class="text-right align-middle">
                                            @if($offlinePayment->status != 'approved')
                                                <div class="btn-group dropdown table-actions">
                                                    <button type="button" class="btn-transparent dropdown-toggle"
                                                            data-toggle="dropdown"
                                                            aria-haspopup="true" aria-expanded="false">
                                                        <i data-feather="more-vertical" height="20"></i>
                                                    </button>
                                                    <div class="dropdown-menu">
                                                        <a href="/panel/financial/offline-payments/{{ $offlinePayment->id }}/edit"
                                                           class="webinar-actions d-block mt-10">{{ trans('public.edit') }}</a>
                                                        <a href="/panel/financial/offline-payments/{{ $offlinePayment->id }}/delete" data-item-id="1"
                                                           class="webinar-actions d-block mt-10 delete-action">{{ trans('public.delete') }}</a>
                                                    </div>
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </section>

    @else

        @include(getTemplate() . '.includes.no-result',[
            'file_name' => 'offline.png',
            'title' => trans('financial.offline_no_result'),
            'hint' => nl2br(trans('financial.offline_no_result_hint')),
        ])

    @endif
    @include('web.default.cart.channels.espay_modal')
@endsection

@push('scripts_bottom')
    <script src="/assets/default/vendors/daterangepicker/daterangepicker.min.js"></script>

    <script src="/assets/default/js/panel/financial/account.min.js?v={{ time() }}"></script>

    <style>
        .topup-preset-box {
            transition: all 0.2s ease;
            background-color: #f8f9fa;
        }
        .topup-preset-box:hover {
            border-color: #298AF2 !important;
            background-color: #eaf3fd;
        }
        .topup-preset-box.active {
            border-color: #298AF2 !important;
            background-color: #298AF2;
        }
        .topup-preset-box.active span {
            color: #fff !important;
        }
    </style>

    <script>
        (function ($) {
            "use strict";

            // Efek klik untuk tombol TopUP Ala Shopee
            $('.topup-preset-box').on('click', function() {
                $('.topup-preset-box').removeClass('active');
                $(this).addClass('active');

                var selectedAmount = $(this).attr('data-amount');
                $('#customAmountInput').val(selectedAmount);
            });

            // Jika diketik manual, hilangkan sorotan preset
            $('#customAmountInput').on('input', function() {
                $('.topup-preset-box').removeClass('active');
            });

            // Top up via Espay: pilih VA / QRIS di popup (tanpa pindah halaman)
            // dipasang langsung di tombol agar jalan sebelum handler submit di account.min.js
            $('#submitChargeAccountForm').on('click', function (e) {
                var $form = $(this).closest('form');
                if ($form.find('input[name="gateway"]:checked').val() !== 'Espay' || !window.EspayCheckout) {
                    return;
                }
                e.preventDefault();
                e.stopPropagation();

                var $btn = $(this);
                var label = $btn.text();
                $btn.addClass('loadingbar primary').prop('disabled', true);

                $.ajax({url: $form.attr('action'), method: 'POST', data: $form.serialize(), dataType: 'json', headers: {'X-Requested-With': 'XMLHttpRequest'}})
                    .done(function (res) {
                        if (!window.EspayCheckout.handlePaymentResponse(res)) {
                            if (res && res.redirect_url) {
                                window.location.href = res.redirect_url;
                                return;
                            }
                            $form[0].submit();
                        }
                    })
                    .fail(function (xhr) {
                        var msg = window.EspayCheckout.ajaxError(xhr, 'Gagal memproses top up. Silakan coba lagi.');
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({icon: 'warning', title: 'Perhatian', text: msg, confirmButtonText: 'Mengerti'});
                        } else {
                            alert(msg);
                        }
                    })
                    .always(function () {
                        $btn.removeClass('loadingbar primary').prop('disabled', false).text(label);
                    });
            });

            @if(session()->has('sweetalert'))
            Swal.fire({
                icon: "{{ session()->get('sweetalert')['status'] ?? 'success' }}",
                html: '<h3 class="font-20 text-center text-dark-blue py-25">{{ session()->get('sweetalert')['msg'] ?? '' }}</h3>',
                showConfirmButton: false,
                width: '25rem',
            });
            @endif

        })(jQuery)
    </script>
@endpush
