@extends(getTemplate() .'.panel.layouts.panel_layout')

@push('styles_top')
    <link rel="stylesheet" href="/assets/default/css/espay-checkout.css?v={{ @filemtime(public_path('assets/default/css/espay-checkout.css')) }}">
@endpush

@section('content')
    <section class="espay-page">
        <h2 class="section-title">Pembayaran</h2>

        <div class="bg-white panel-shadow rounded-lg p-20 mt-20 js-espay-page-host">
            @include('web.default.cart.channels.espay_content')
        </div>
    </section>
@endsection

@push('scripts_bottom')
    <script src="/assets/default/js/parts/espay-checkout.js?v={{ @filemtime(public_path('assets/default/js/parts/espay-checkout.js')) }}"></script>
    <script>
        window.EspayCheckout.mount(jQuery('.js-espay-page-host'));
    </script>
@endpush
