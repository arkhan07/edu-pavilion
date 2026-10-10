<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Espay\EspayClient;
use App\Services\Espay\EspayPaymentService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Simulasi request SNAP Inquiry / Payment dari Espay ke endpoint lokal (sandbox saja).
 * Memakai pasangan kunci sementara: request ditandatangani dengan private key sementara dan
 * public key Espay di config diganti sementara (hanya dalam proses ini).
 */
class EspaySimulate extends Command
{
    protected $signature = 'espay:simulate
                            {order : ID order (tabel orders)}
                            {--type=payment : inquiry | payment}
                            {--invalid-signature : Kirim signature yang salah}
                            {--status=S : additionalInfo.transactionStatus untuk payment (S, F, IP, ...)}
                            {--amount= : Nominal dibayar (default = nominal order); untuk uji VA Static Open}
                            {--trx= : trxId (default acak); pakai trxId sama untuk uji notifikasi ganda}';

    protected $description = 'Simulasikan request SNAP Espay (Inquiry / Payment) ke endpoint lokal';

    public function handle()
    {
        if ((new EspayClient())->isProduction()) {
            $this->error('Simulasi hanya boleh di mode SANDBOX (ESPAY_IS_PRODUCTION=false).');
            return self::FAILURE;
        }

        $order = Order::find($this->argument('order'));

        if (!EspayPaymentService::isEspayOrder($order) or empty($order->reference_id)) {
            $this->error('Order tidak ditemukan atau belum memilih metode pembayaran Espay.');
            return self::FAILURE;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + EspayClient::opensslConfig());
        EspayClient::$overrides = ['espay_public_key' => openssl_pkey_get_details($key)['key']];

        $merchantCode = (new EspayClient())->merchantCode();
        $paymentData = EspayPaymentService::paymentData($order);
        $amount = EspayPaymentService::formatAmount($this->option('amount') ?: ($paymentData['amount'] ?? 0));
        // VA Static Open: Espay mengirim nomor VA tetap milik user, bukan nomor transaksi
        $virtualAccountNo = $paymentData['va_number'] ?? $order->reference_id;
        $isInquiry = $this->option('type') === 'inquiry';
        $path = '/payments/espay/v1.0/transfer-va/' . ($isInquiry ? 'inquiry' : 'payment');
        $now = EspayClient::timestamp();

        $body = $isInquiry ? [
            'partnerServiceId' => EspayClient::partnerServiceId(),
            'customerNo' => $merchantCode,
            'virtualAccountNo' => $virtualAccountNo,
            'trxDateInit' => $now,
            'inquiryRequestId' => (string) Str::uuid(),
        ] : [
            'partnerServiceId' => EspayClient::partnerServiceId(),
            'customerNo' => $merchantCode,
            'virtualAccountNo' => $virtualAccountNo,
            'trxId' => $trxId = ($this->option('trx') ?: 'ESP' . strtoupper(Str::random(12))),
            'paymentRequestId' => (string) Str::uuid(),
            'paidAmount' => ['value' => $amount, 'currency' => 'IDR'],
            'totalAmount' => ['value' => $amount, 'currency' => 'IDR'],
            'trxDateTime' => $now,
            'additionalInfo' => [
                'transactionStatus' => strtoupper($this->option('status')),
                'memberCode' => $order->reference_id,
                'productCode' => $paymentData['product_code'] ?? 'SIMULATOR',
                // payment_ref mengikuti trxId -> --trx yang sama = notifikasi ganda
                'paymentRef' => 'SIM' . $trxId,
            ],
        ];

        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        openssl_sign(EspayClient::stringToSign('POST', $path, $json, $now), $signature, $key, OPENSSL_ALGO_SHA256);
        $signature = base64_encode($signature);

        if ($this->option('invalid-signature')) {
            $signature = base64_encode(random_bytes(256));
        }

        $request = Request::create($path, 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TIMESTAMP' => $now,
            'HTTP_X_SIGNATURE' => $signature,
            'HTTP_X_EXTERNAL_ID' => EspayClient::externalId(),
            'HTTP_X_PARTNER_ID' => $merchantCode,
            'HTTP_CHANNEL_ID' => 'ESPAY',
        ], $json);

        $response = app()->handle($request);

        $this->line("POST {$path} -> HTTP {$response->getStatusCode()}");
        $this->line($response->getContent());
        $this->info('Status order sekarang: ' . $order->refresh()->status);

        return self::SUCCESS;
    }
}
