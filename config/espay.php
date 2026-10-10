<?php

/*
|--------------------------------------------------------------------------
| Espay Payment Gateway - SNAP (Standar Nasional Open API Pembayaran)
|--------------------------------------------------------------------------
| Docs: https://docs.espay.id (Payment Gateway SNAP)
|
| ESPAY_IS_PRODUCTION=false -> SANDBOX, true -> PRODUCTION
|
| Kunci RSA (format PEM):
|   - private_key      : private key MERCHANT (kita) untuk menandatangani request ke Espay.
|                        Public key pasangannya dikirim ke tim Espay. Buat dengan: php artisan espay:keys
|   - espay_public_key : public key dari Espay (atau dari ASPI Dev Site saat pengujian Client Simulator)
|                        untuk memvalidasi request Inquiry & Payment yang masuk.
|
| URL yang didaftarkan ke Espay Sandbox Portal / ASPI Client Simulator:
|   Inquiry : {APP_URL}/payments/espay/v1.0/transfer-va/inquiry
|   Payment : {APP_URL}/payments/espay/v1.0/transfer-va/payment
*/

return [
    'is_production' => env('ESPAY_IS_PRODUCTION', false),

    'sandbox' => [
        'api_url' => env('ESPAY_SANDBOX_API_URL', 'https://sandbox-api.espay.id'),
        'merchant_info_url' => env('ESPAY_SANDBOX_MERCHANT_INFO_URL', 'https://sandbox-api.espay.id/rest/merchant/merchantinfo'),
        'send_invoice_url' => env('ESPAY_SANDBOX_SEND_INVOICE_URL', 'https://sandbox-api.espay.id/rest/merchantpg/sendinvoice'),
        'check_status_url' => env('ESPAY_SANDBOX_CHECK_STATUS_URL', 'https://sandbox-api.espay.id/rest/merchant/status'),
        'merchant_code' => env('ESPAY_SANDBOX_MERCHANT_CODE'),   // X-PARTNER-ID / merchantId / customerNo
        'api_key' => env('ESPAY_SANDBOX_API_KEY'),               // Merchant Info key & subMerchantId
        'signature_key' => env('ESPAY_SANDBOX_SIGNATURE_KEY'),   // non-SNAP signature key
        'password' => env('ESPAY_SANDBOX_PASSWORD'),             // non-SNAP password
        'disbursement_partner_id' => env('ESPAY_SANDBOX_DISB_PARTNER_ID'),        // X-PARTNER-ID disbursement (kosong = merchant_code)
        'disbursement_source_account' => env('ESPAY_SANDBOX_DISB_SOURCE_ACCOUNT'), // rekening sumber dana (sourceAccountNo)
        'disbursement_source_bank_code' => env('ESPAY_SANDBOX_DISB_SOURCE_BANK_CODE'), // kode bank rekening sumber
        'private_key' => env('ESPAY_SANDBOX_PRIVATE_KEY', 'storage/app/espay/sandbox/private.pem'),
        'espay_public_key' => env('ESPAY_SANDBOX_ESPAY_PUBLIC_KEY', 'storage/app/espay/sandbox/espay_public.pem'),
    ],

    'production' => [
        'api_url' => env('ESPAY_PRODUCTION_API_URL', 'https://api-merchant.espay.id'),
        'merchant_info_url' => env('ESPAY_PRODUCTION_MERCHANT_INFO_URL', 'https://api.espay.id/rest/merchant/merchantinfo'),
        'send_invoice_url' => env('ESPAY_PRODUCTION_SEND_INVOICE_URL', 'https://api-merchant.espay.id/rest/merchantpg/sendinvoice'),
        'check_status_url' => env('ESPAY_PRODUCTION_CHECK_STATUS_URL', 'https://api.espay.id/rest/merchant/status'),
        'merchant_code' => env('ESPAY_PRODUCTION_MERCHANT_CODE'),
        'api_key' => env('ESPAY_PRODUCTION_API_KEY'),
        'signature_key' => env('ESPAY_PRODUCTION_SIGNATURE_KEY'),   // non-SNAP signature key
        'password' => env('ESPAY_PRODUCTION_PASSWORD'),             // non-SNAP password
        'disbursement_partner_id' => env('ESPAY_PRODUCTION_DISB_PARTNER_ID'),        // X-PARTNER-ID disbursement (kosong = merchant_code)
        'disbursement_source_account' => env('ESPAY_PRODUCTION_DISB_SOURCE_ACCOUNT'), // rekening sumber dana (sourceAccountNo)
        'disbursement_source_bank_code' => env('ESPAY_PRODUCTION_DISB_SOURCE_BANK_CODE'), // kode bank rekening sumber
        'private_key' => env('ESPAY_PRODUCTION_PRIVATE_KEY', 'storage/app/espay/production/private.pem'),
        'espay_public_key' => env('ESPAY_PRODUCTION_ESPAY_PUBLIC_KEY', 'storage/app/espay/production/espay_public.pem'),
    ],

    /*
    | Disbursement (withdraw ke rekening guru/user) - SNAP Transfer.
    | Semua pencairan dana (panel guru/organisasi & tombol Payout admin) diproses via Espay.
    | Path mengikuti standar SNAP BI; sesuaikan via .env bila dokumentasi Espay berbeda.
    | Callback yang didaftarkan ke Espay:
    |   Transfer Confirmation (96): {APP_URL}/payments/espay/v1.0/transfer/confirmation
    |   Transfer Notification (97): {APP_URL}/payments/espay/v1.0/transfer/notification
    */
    'disbursement' => [
        // docs.espay.id > Disbursement (host sama dengan Payment Gateway: sandbox-api.espay.id / api-merchant.espay.id)
        'paths' => [
            'balance_inquiry' => env('ESPAY_DISB_PATH_BALANCE', '/api/v1.0/balance-inquiry'),
            'bank_statement' => env('ESPAY_DISB_PATH_STATEMENT', '/api/v1.0/bank-statement'),
            'account_inquiry_internal' => env('ESPAY_DISB_PATH_INQUIRY_INTERNAL', '/api/v1.0/account-inquiry-internal'),
            'account_inquiry_external' => env('ESPAY_DISB_PATH_INQUIRY_EXTERNAL', '/api/v1.0/account-inquiry-external'),
            'transfer_intrabank' => env('ESPAY_DISB_PATH_INTRABANK', '/api/v1.0/transfer-intrabank'),
            'transfer_interbank' => env('ESPAY_DISB_PATH_INTERBANK', '/api/v1.0/transfer-interbank'),
            'transfer_status' => env('ESPAY_DISB_PATH_STATUS', '/api/v1.0/transfer/status'),
        ],
        // Nilai bawaan sumber dana (model Deposit Espay: sourceAccountNo "PTPLUS"); bisa diganti di Admin per mode
        'default_source_account' => env('ESPAY_DISB_DEFAULT_SOURCE_ACCOUNT', 'PTPLUS'),
        'default_source_bank_code' => env('ESPAY_DISB_DEFAULT_SOURCE_BANK_CODE', '022'), // arahan Espay 07-10-2026
        'source_account_name' => env('ESPAY_DISB_SOURCE_ACCOUNT_NAME', 'PTPLUS'),
        // OUR = biaya transfer ditanggung merchant (guru menerima nominal penuh), BEN = dipotong dari penerima
        'fee_type' => env('ESPAY_DISB_FEE_TYPE', 'OUR'),
        // Field wajib bila bank sumber Permata (013) - lihat dokumentasi BI Fast
        'purpose_of_transaction' => env('ESPAY_DISB_PURPOSE', '99'),     // 01 investasi, 02 transfer kekayaan, 03 pembelian, 99 lainnya
        'beneficiary_account_type' => 'SVGS',                              // rekening tabungan
        'beneficiary_customer_type' => '01',                               // perorangan
        'minimum_amount' => (int) env('ESPAY_DISB_MINIMUM_AMOUNT', 10000),
        'maximum_amount' => (int) env('ESPAY_DISB_MAXIMUM_AMOUNT', 250000000), // batas BI-FAST per transaksi
        // Saldo deposit Espay (Balance Inquiry) harus >= nominal + cadangan biaya ini; bila kurang, payout ditahan
        'deposit_fee_buffer' => (int) env('ESPAY_DISB_DEPOSIT_FEE_BUFFER', 5000),
        // pencairan yang masih processing lebih lama dari ini dicek ulang via Inquiry Status
        'status_check_after_minutes' => (int) env('ESPAY_DISB_STATUS_CHECK_MINUTES', 15),
    ],

    // Kode bank BI (beneficiaryBankCode). Kunci = nama bank (huruf kecil) seperti diisi user/guru.
    'bank_codes' => [
        'bca' => '014', 'bank central asia' => '014',
        'bri' => '002', 'bank rakyat indonesia' => '002',
        'mandiri' => '008', 'bank mandiri' => '008',
        'bni' => '009', 'bank negara indonesia' => '009',
        'cimb' => '022', 'cimb niaga' => '022',
        'permata' => '013', 'bank permata' => '013',
        'danamon' => '011', 'bank danamon' => '011',
        'bsi' => '451', 'bank syariah indonesia' => '451', 'bsm' => '451',
        'btn' => '200', 'bank tabungan negara' => '200',
        'muamalat' => '147', 'bmi' => '147',
        'maybank' => '016', 'bii' => '016',
        'ocbc' => '028', 'ocbc nisp' => '028',
        'panin' => '019',
        'mega' => '426', 'bank mega' => '426',
        'btpn' => '213', 'jenius' => '213',
        'jago' => '542', 'bank jago' => '542',
        'seabank' => '535',
        'bca syariah' => '536',
        'bjb' => '110', 'bank bjb' => '110',
        'dki' => '111', 'bank dki' => '111',
        'artha graha' => '037', 'bag' => '037',
        'sinarmas' => '153',
    ],

    /*
    | Virtual Account: tipe VA merchant Edu Pavilion = Static Open (nomor tetap per user, nominal bebas).
    | Dibuat via Direct API sendinvoice (Hash-Based Signature, signature_key), callback hanya Payment.
    | Pembayaran dengan nominal = order pending -> order lunas; selain itu -> top up saldo.
    */
    'va_type' => env('ESPAY_VA_TYPE', 'static_open'),
    'static_va_expired_minutes' => (int) env('ESPAY_STATIC_VA_EXPIRED_MINUTES', 525600), // 1 tahun, diperpanjang otomatis
    // QR MPM: productCode "QRIS" (QRISPLUS dari Merchant Info adalah produk halaman Espay, bukan QR MPM)
    'qris_product_code' => env('ESPAY_QRIS_PRODUCT_CODE', 'QRIS'),

    // CHANNEL-ID & partnerServiceId (padding spasi 8 karakter: " ESPAY")
    'channel_id' => 'ESPAY',

    // Prefix nomor transaksi (virtualAccountNo / partnerReferenceNo, alfanumerik saja)
    'payment_id_prefix' => env('ESPAY_PAYMENT_ID_PREFIX', 'EDU'),

    // Masa berlaku transaksi (menit)
    'expiry_minutes' => (int) env('ESPAY_EXPIRY_MINUTES', 1440),

    'minimum_amount' => (int) env('ESPAY_MINIMUM_AMOUNT', 10000),
];
