{{-- Popup checkout Espay. Sertakan sekali di halaman yang memanggil /payments/payment-request via AJAX. --}}
<link rel="stylesheet" href="/assets/default/css/espay-checkout.css?v={{ @filemtime(public_path('assets/default/css/espay-checkout.css')) }}">

<div class="modal fade espay-modal" id="espayPaymentModal" tabindex="-1" role="dialog" aria-labelledby="espayPaymentModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="espay-modal__head">
                <h5 class="espay-modal__title" id="espayPaymentModalTitle">Pilih Metode Pembayaran</h5>
                <button type="button" class="espay-modal__close" data-dismiss="modal" aria-label="Tutup">&times;</button>
            </div>
            <div class="espay-modal__body js-espay-modal-body"></div>
        </div>
    </div>
</div>

@push('scripts_bottom')
    <script src="/assets/default/js/parts/espay-checkout.js?v={{ @filemtime(public_path('assets/default/js/parts/espay-checkout.js')) }}"></script>
@endpush
