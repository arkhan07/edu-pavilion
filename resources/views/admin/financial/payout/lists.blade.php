@extends('admin.layouts.app')

@push('styles_top')
    <link rel="stylesheet" href="/assets/default/vendors/sweetalert2/dist/sweetalert2.min.css">
@endpush

@section('content')
    <section class="section">
        <div class="section-header">
            <h1>{{ $pageTitle }}</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="{{ getAdminPanelUrl() }}">{{trans('admin/main.dashboard')}}</a>
                </div>
                <div class="breadcrumb-item">{{ trans('admin/main.payouts') }}</div>
            </div>
        </div>


        <div class="section-body">

            @if(request()->get('payout', 'requests') == 'requests' and isset($espayDeposit))
                <section class="card">
                    <div class="card-body d-flex flex-wrap align-items-center justify-content-between">
                        <div>
                            <div class="text-muted font-12">Saldo deposit Espay (Disbursement)</div>
                            <div class="font-20 font-weight-bold {{ is_null($espayDeposit) ? 'text-danger' : 'text-dark' }}">
                                {{ is_null($espayDeposit) ? 'Tidak dapat dicek' : handlePrice($espayDeposit) }}
                            </div>
                            <div class="text-muted font-12">Diperbarui tiap 1 menit. Pencairan otomatis berjalan bila saldo deposit cukup.</div>
                        </div>
                        @if(!empty($espayHeld) and $espayHeld->total > 0)
                            <div class="text-right mt-2 mt-md-0">
                                <span class="badge badge-warning">{{ $espayHeld->total }} payout menunggu deposit</span>
                                <div class="font-14 mt-1">Total {{ handlePrice($espayHeld->amount) }}</div>
                                <div class="text-muted font-12">Diproses otomatis setelah deposit diisi (cek tiap 10 menit).</div>
                            </div>
                        @endif
                    </div>
                </section>
            @endif

            <section class="card">
                <div class="card-body">
                    <form method="get" class="mb-0">
                        <input type="hidden" name="payout" value="{{ request()->get('payout') }}">

                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="input-label">{{ trans('admin/main.search') }}</label>
                                    <input type="text" class="form-control text-center" name="search" value="{{ request()->get('search') }}">
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="input-label">{{ trans('admin/main.start_date') }}</label>
                                    <div class="input-group">
                                        <input type="date" id="fsdate" class="text-center form-control" name="from" value="{{ request()->get('from') }}" placeholder="Start Date">
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="input-label">{{ trans('admin/main.end_date') }}</label>
                                    <div class="input-group">
                                        <input type="date" id="lsdate" class="text-center form-control" name="to" value="{{ request()->get('to') }}" placeholder="End Date">
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="input-label">{{ trans('admin/main.role') }}</label>
                                    <select name="role_id" data-plugin-selectTwo class="form-control populate">
                                        <option value="">{{ trans('admin/main.all_roles') }}</option>
                                        @foreach($roles as $role)
                                            <option value="{{ $role->id }}" @if($role->id == request()->get('role_id')) selected @endif>{{ $role->caption }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="input-label">{{ trans('admin/main.user') }}</label>
                                    <select name="user_ids[]" multiple="multiple" class="form-control search-user-select2"
                                            data-placeholder="Search teachers">

                                        @if(!empty($users) and $users->count() > 0)
                                            @foreach($users as $user_filter)
                                                <option value="{{ $user_filter->id }}" selected>{{ $user_filter->full_name }}</option>
                                            @endforeach
                                        @endif
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="input-label">{{ trans('admin/main.bank') }}</label>
                                    <select name="account_type" data-plugin-selectTwo class="form-control populate">
                                        <option value="">{{ trans('admin/main.all_banks') }}</option>

                                        @foreach($offlineBanks as $offlineBank)
                                            <option value="{{ $offlineBank->id }}" @if(request()->get('account_type') == $offlineBank->id) selected @endif>{{ $offlineBank->title }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="input-label">{{ trans('admin/main.filters') }}</label>
                                    <select name="sort" data-plugin-selectTwo class="form-control populate">
                                        <option value="">Filter Type</option>
                                        <option value="amount_asc" @if(request()->get('sort') == 'amount_asc') selected @endif>{{ trans('admin/main.amount_ascending') }}</option>
                                        <option value="amount_desc" @if(request()->get('sort') == 'amount_desc') selected @endif>{{ trans('admin/main.amount_descending') }}</option>
                                        <option value="created_at_asc" @if(request()->get('sort') == 'created_at_asc') selected @endif>{{ trans('admin/main.last_payout_date_ascending') }}</option>
                                        <option value="created_at_desc" @if(request()->get('sort') == 'created_at_desc') selected @endif>{{ trans('admin/main.last_payout_date_descending') }}</option>
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="input-label mb-4"> </label>
                                    <input type="submit" class="text-center btn btn-primary w-100" value="{{ trans('admin/main.show_results') }}">
                                </div>
                            </div>
                        </div>

                    </form>
                </div>
            </section>

            <div class="row">
                <div class="col-12 col-md-12">
                    <div class="card">
                        <div class="card-header">
                            @can('admin_payouts_export_excel')
                                <a href="{{ getAdminPanelUrl() }}/financial/payouts/excel?{{ http_build_query(request()->all()) }}" class="btn btn-primary">{{ trans('admin/main.export_xls') }}</a>
                            @endcan
                        </div>

                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped font-14">
                                    <tr>
                                        <th>{{ trans('admin/main.user') }}</th>
                                        <th>{{ trans('admin/main.role') }}</th>
                                        <th>{{ trans('admin/main.payout_amount') }}</th>
                                        <th class="">{{ trans('admin/main.bank') }}</th>

                                        <th>{{ trans('admin/main.phone') }}</th>
                                        <th>Keterangan Tambahan</th>
                                        <th width="180px">{{ trans('admin/main.last_payout_date') }}</th>

                                        @if(request()->get('payout') == 'history')
                                            <th>{{ trans('admin/main.status') }}</th>
                                        @endif

                                        <th width="150px">{{ trans('admin/main.actions') }}</th>
                                    </tr>

                                    @if($payouts->count() > 0)
                                        @foreach($payouts as $payout)
                                            @php
                                                $isAuto = !empty($payout->is_automatic);
                                                $recipientUser = optional($payout->userSelectedBank)->user;
                                                $isOrgToTeacher = $recipientUser && $recipientUser->id != $payout->user_id;
                                            @endphp
                                            <tr @if($isAuto) style="background:#f0f7ff;" @endif>
                                                <td class="text-left">
                                                    <span class="d-block font-weight-500">{{ $payout->user->full_name }}</span>
                                                    <span class="font-12 text-muted">{{ $payout->user->role->caption }}</span>
                                                    @if($isOrgToTeacher)
                                                        <span class="d-block font-12 mt-1">
                                                            <span class="badge badge-warning" style="font-size:10px;">Organisasi → Pengajar</span>
                                                            <span class="d-block text-primary font-weight-500 mt-1"><i class="fa fa-arrow-right mr-1"></i>Ke: {{ $recipientUser->full_name }}</span>
                                                        </span>
                                                    @elseif($isAuto)
                                                        <span class="d-block font-12 mt-1"><span class="badge badge-info" style="font-size:10px;">Pengajar • Otomatis</span></span>
                                                    @endif
                                                    @if($isAuto)
                                                        <span class="badge badge-primary mt-1" style="font-size:10px;"><i class="fa fa-bolt mr-1"></i>{{ ucfirst($payout->provider ?: 'flip') }} Otomatis</span>
                                                    @else
                                                        <span class="badge badge-light border mt-1" style="font-size:10px;">Manual</span>
                                                    @endif
                                                </td>

                                                <td>{{ $payout->user->role->caption }}</td>

                                                <td>
                                                    <span class="font-weight-500">{{ handlePrice($payout->amount) }}</span>
                                                    @if($isAuto)
                                                        <span class="d-block font-12 text-primary">via {{ ucfirst($payout->provider ?: 'flip') }}</span>
                                                    @endif
                                                </td>


                                                @if(!empty($payout->userSelectedBank->bank))
                                                <td class="">
                                                    @php
                                                        $bank = $payout->userSelectedBank->bank;
                                                    @endphp
                                                    <div class="font-weight-500">{{ $bank->title }}</div>
                                                    @if($isOrgToTeacher)
                                                        <div class="font-12 text-muted">Rek. {{ $recipientUser->full_name }}</div>
                                                    @endif

                                                    {{-- For Modal --}}
                                                    <input type="hidden" class="js-bank-details" data-name="{{ trans("admin/main.bank") }}" value="{{ $bank->title }}">
                                                    @foreach($bank->specifications as $specification)
                                                        @php
                                                            $selectedBankSpecification = $payout->userSelectedBank->specifications->where('user_selected_bank_id', $payout->userSelectedBank->id)->where('user_bank_specification_id', $specification->id)->first();
                                                        @endphp

                                                        @if(!empty($selectedBankSpecification))
                                                            <input type="hidden" class="js-bank-details" data-name="{{ $specification->name }}" value="{{ $selectedBankSpecification->value }}">
                                                        @endif
                                                    @endforeach

                                                </td>
                                                @else
                                                <td>-</td>
                                                @endif

                                                <td>{{ $payout->user->mobile }}</td>

                                                <td style="max-width:220px;">
                                                    @php $notes = $webinarNotesMap[$payout->user_id] ?? []; @endphp
                                                    @if(!empty($notes))
                                                        @foreach($notes as $note)
                                                            <span class="d-block font-12" style="white-space:pre-wrap;word-break:break-word;line-height:1.4;" title="{{ $note }}">{{ \Illuminate\Support\Str::limit($note, 90) }}</span>
                                                            @if(!$loop->last)<hr class="my-1">@endif
                                                        @endforeach
                                                    @else
                                                        <span class="text-muted font-12">-</span>
                                                    @endif
                                                </td>

                                                <td>
                                                    {{ dateTimeFormat($payout->created_at, 'j M Y H:i') }}
                                                    @if($payout->status === \App\Models\Payout::$waiting and in_array($payout->provider_status, \App\Services\Espay\EspayDisbursementService::HOLD_STATUSES, true))
                                                        <span class="d-block"><span class="badge badge-warning mt-1" style="font-size:10px;" title="Diproses otomatis setelah saldo deposit Espay cukup">Menunggu deposit Espay</span></span>
                                                    @endif
                                                </td>

                                                @if(request()->get('payout') == 'history')
                                                    <td>
                                                        <span class="{{ ($payout->status == 'done') ? 'text-success' : ($payout->status == 'processing' ? 'text-warning' : 'text-danger') }}">{{ trans('public.'.$payout->status) }}</span>
                                                        @if(!empty($payout->provider))
                                                            <div class="font-12 text-muted">via {{ ucfirst($payout->provider) }}{{ !empty($payout->provider_reference) ? ' · ' . $payout->provider_reference : '' }}</div>
                                                        @endif
                                                        @if($isAuto)
                                                            <span class="d-block"><span class="badge badge-primary mt-1" style="font-size:10px;">Otomatis</span></span>
                                                        @endif
                                                    </td>
                                                @endif


                                                <td width="150px">
                                                    <div class="">
                                                        <button type="button" class="js-show-details btn-sm btn-transparent text-primary" data-toggle="tooltip" data-placement="top" title="{{ trans('update.show_details') }}">
                                                            <i class="fa fa-eye"></i>
                                                        </button>

                                                        @if(request()->get('payout') == 'requests' and $payout->status === \App\Models\Payout::$waiting)

                                                            @can('admin_payouts_payout')
                                                                @include('admin.includes.delete_button',[
                                                                        'url' => getAdminPanelUrl().'/financial/payouts/'. $payout->id .'/payout',
                                                                        'tooltip' => trans('admin/main.payout') . ' (Otomatis via Espay)',
                                                                        'btnClass' => 'ml-2',
                                                                        'btnIcon' => 'fa-credit-card'
                                                                    ])

                                                                @include('admin.includes.delete_button',[
                                                                        'url' => getAdminPanelUrl().'/financial/payouts/'. $payout->id .'/payout-manual',
                                                                        'tooltip' => 'Payout Manual',
                                                                        'btnClass' => 'ml-1 text-warning',
                                                                        'btnIcon' => 'fa-hand-holding-usd'
                                                                    ])
                                                            @endcan

                                                            @can('admin_payouts_reject')
                                                                @include('admin.includes.delete_button',[
                                                                        'url' => getAdminPanelUrl().'/financial/payouts/'. $payout->id .'/reject',
                                                                        'tooltip' => trans('public.reject'),
                                                                        'btnIcon' => 'fa-times-circle',
                                                                        'btnClass' => 'ml-2',
                                                                    ])
                                                            @endcan
                                                        @elseif(request()->get('payout') == 'history')
                                                            @can('admin_payouts_reject')
                                                                @include('admin.includes.delete_button',[
                                                                        'url' => getAdminPanelUrl().'/financial/payouts/'. $payout->id .'/revert',
                                                                        'tooltip' => 'Batalkan',
                                                                        'btnIcon' => 'fa-undo',
                                                                        'btnClass' => 'ml-2 text-danger',
                                                                    ])
                                                            @endcan
                                                        @endif
                                                    </div>
                                                </td>

                                            </tr>
                                        @endforeach
                                    @endif

                                </table>
                            </div>
                        </div>

                        <div class="card-footer text-center">
                            {{ $payouts->appends(request()->input())->links() }}
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-body">
            <div class="section-title ml-0 mt-0 mb-3"><h5>{{trans('admin/main.hints')}}</h5></div>
            <div class="row">
                <div class="col-md-6">
                    <div class="media-body">
                        <div class="text-primary mt-0 mb-1 font-weight-bold">{{trans('admin/main.payout_list_hint_title_1')}}</div>
                        <div class=" text-small font-600-bold">{{trans('admin/main.payout_list_hint_description_1')}}</div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="media-body">
                        <div class="text-primary mt-0 mb-1 font-weight-bold">{{trans('admin/main.payout_list_hint_title_2')}}</div>
                        <div class=" text-small font-600-bold">{{trans('admin/main.payout_list_hint_description_2')}}</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

@endsection

@push('scripts_bottom')
    <script>
        var payoutDetailsLang = '{{ trans('update.payout_details') }}';
        var closeLang = '{{ trans('public.close') }}';
    </script>

    <script src="/assets/default/vendors/sweetalert2/dist/sweetalert2.min.js"></script>
    <script src="/assets/default/js/admin/payout.min.js"></script>
@endpush
