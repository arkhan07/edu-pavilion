<?php

namespace App\Services\Espay;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Client Espay Payment Gateway SNAP.
 *
 * Signature (asymmetric, SHA256withRSA):
 *   StringToSign = METHOD:RelativeUrl:lower(hex(sha256(minify(body)))):X-TIMESTAMP
 *   Merchant -> Espay : ditandatangani dengan private key merchant
 *   Espay -> Merchant : divalidasi dengan public key Espay
 */
class EspayClient
{
    public const SERVICE_PAYMENT_HOST_TO_HOST = '/apimerchant/v1.0/debit/payment-host-to-host';
    public const SERVICE_QR_MPM = '/api/v1.0/qr/qr-mpm-generate';
    public const SERVICE_INQUIRY_STATUS = '/apimerchant/v1.0/transfer-va/status';
    public const SERVICE_DELETE_VA = '/apimerchant/v1.0/transfer-va/delete-va';

    protected bool $isProduction;
    protected array $config;
    protected ?string $lastExternalId = null;

    /**
     * Override nilai kredensial dalam proses ini saja (dipakai espay:simulate).
     */
    public static array $overrides = [];

    public function __construct(?bool $isProduction = null)
    {
        // Prioritas: pengaturan Admin Panel (payment channel Espay) -> .env (config/espay.php)
        $settings = EspaySettings::current();
        $mode = $settings['mode'] ?? null;

        $this->isProduction = $isProduction ?? ($mode !== null ? $mode === 'production' : (bool) config('espay.is_production', false));
        $env = $this->isProduction ? 'production' : 'sandbox';

        $this->config = array_merge(config("espay.{$env}", []), $settings[$env] ?? [], self::$overrides);
    }

    public function isProduction(): bool
    {
        return $this->isProduction;
    }

    public function getModeLabel(): string
    {
        return $this->isProduction ? 'PRODUCTION' : 'SANDBOX';
    }

    public function merchantCode(): ?string
    {
        return $this->cfg('merchant_code');
    }

    public function apiKey(): ?string
    {
        return $this->cfg('api_key');
    }

    /**
     * Signature key non-SNAP (Hash-Based Signature: sendinvoice & payment notification).
     */
    public function signatureKey(): ?string
    {
        return $this->cfg('signature_key');
    }

    /**
     * Password yang dikirim Espay pada payment notification non-SNAP.
     */
    public function notificationPassword(): ?string
    {
        return $this->cfg('password');
    }

    /**
     * Hash-Based Signature non-SNAP: sha256(UPPER("##key##part1##...##")).
     */
    public static function hashSignature(string $key, array $parts): string
    {
        return hash('sha256', strtoupper('##' . $key . '##' . implode('##', $parts) . '##'));
    }

    /**
     * X-PARTNER-ID untuk layanan Disbursement (kosong = merchant code Payment Gateway).
     */
    public function disbursementPartnerId(): ?string
    {
        return $this->cfg('disbursement_partner_id') ?: $this->merchantCode();
    }

    public function disbursementSourceAccount(): string
    {
        return $this->cfg('disbursement_source_account') ?: (string) config('espay.disbursement.default_source_account', 'PTPLUS');
    }

    public function disbursementSourceBankCode(): string
    {
        return $this->cfg('disbursement_source_bank_code') ?: (string) config('espay.disbursement.default_source_bank_code', '013');
    }

    public static function partnerServiceId(): string
    {
        return str_pad((string) config('espay.channel_id', 'ESPAY'), 8, ' ', STR_PAD_LEFT);
    }

    public static function timestamp(?Carbon $time = null): string
    {
        return ($time ?? now())->copy()->setTimezone('Asia/Jakarta')->format('Y-m-d\TH:i:sP');
    }

    public static function externalId(): string
    {
        // Numeric string, unik dalam satu hari
        return now()->format('His') . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT) . substr((string) hrtime(true), -6);
    }

    public static function minify(string $json): string
    {
        $decoded = json_decode($json);

        return $decoded === null ? $json : json_encode($decoded, JSON_UNESCAPED_SLASHES);
    }

    public static function stringToSign(string $method, string $relativeUrl, string $minifiedBody, string $timestamp): string
    {
        return strtoupper($method) . ':' . $relativeUrl . ':' . strtolower(hash('sha256', $minifiedBody)) . ':' . $timestamp;
    }

    public function isConfigured(): bool
    {
        return !empty($this->merchantCode()) and !empty($this->apiKey()) and !empty($this->privateKey());
    }

    public function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            $prefix = $this->isProduction ? 'ESPAY_PRODUCTION_' : 'ESPAY_SANDBOX_';
            throw new RuntimeException("Kredensial Espay {$this->getModeLabel()} belum lengkap ({$prefix}MERCHANT_CODE, {$prefix}API_KEY, private key).");
        }
    }

    public function sign(string $method, string $relativeUrl, string $minifiedBody, string $timestamp): string
    {
        $privateKey = $this->privateKey();

        if (empty($privateKey) or !openssl_sign(self::stringToSign($method, $relativeUrl, $minifiedBody, $timestamp), $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Gagal membuat signature Espay: private key tidak valid.');
        }

        return base64_encode($signature);
    }

    /**
     * Validasi X-SIGNATURE request dari Espay. Body dicoba dalam beberapa bentuk minify
     * karena bentuk JSON pengirim (escape slash/unicode) tidak selalu sama.
     */
    public function verifyIncoming(string $method, string $relativeUrl, string $rawBody, string $timestamp, string $signature): bool
    {
        $publicKey = $this->espayPublicKey();
        $decodedSignature = base64_decode($signature, true);

        if (empty($publicKey) or $decodedSignature === false) {
            return false;
        }

        $decoded = json_decode($rawBody);
        $candidates = array_unique(array_filter([
            $decoded !== null ? json_encode($decoded, JSON_UNESCAPED_SLASHES) : null,
            $decoded !== null ? json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            $decoded !== null ? json_encode($decoded) : null,
            trim($rawBody),
        ]));

        foreach ($candidates as $body) {
            if (openssl_verify(self::stringToSign($method, $relativeUrl, $body, $timestamp), $decodedSignature, $publicKey, OPENSSL_ALGO_SHA256) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Payment Host to Host (service 54) -> webRedirectUrl ke halaman pembayaran Espay.
     */
    public function paymentHostToHost(array $body): array
    {
        return $this->request('POST', self::SERVICE_PAYMENT_HOST_TO_HOST, $body);
    }

    /**
     * QR MPM / QRIS (service 47) -> qrContent, qrImage, qrUrl.
     */
    public function qrMpmGenerate(array $body): array
    {
        return $this->request('POST', self::SERVICE_QR_MPM, $body);
    }

    /**
     * Inquiry Status (service 26). paymentFlagStatus: S, F, SP, IP, EX, WC, WS.
     */
    public function inquiryStatus(string $virtualAccountNo, ?string $inquiryRequestId = null, ?string $paymentRequestId = null): array
    {
        return $this->request('POST', self::SERVICE_INQUIRY_STATUS, array_filter([
            'partnerServiceId' => self::partnerServiceId(),
            'customerNo' => $this->merchantCode(),
            'virtualAccountNo' => $virtualAccountNo,
            'inquiryRequestId' => $inquiryRequestId,
            'paymentRequestId' => $paymentRequestId,
        ], fn ($value) => $value !== null and $value !== ''));
    }

    /**
     * Delete VA (service 31): nonaktifkan transaksi yang masih In Process.
     */
    public function deleteVa(string $virtualAccountNo): array
    {
        return $this->request('DELETE', self::SERVICE_DELETE_VA, [
            'partnerServiceId' => self::partnerServiceId(),
            'customerNo' => $this->merchantCode(),
            'virtualAccountNo' => $virtualAccountNo,
        ]);
    }

    /**
     * Inquiry Merchant Info: daftar bank/produk aktif merchant (di-cache 10 menit).
     */
    public function merchantInfo(): array
    {
        $this->assertConfigured();

        $cacheKey = 'espay.merchant_info.' . strtolower($this->getModeLabel()) . '.' . md5((string) $this->apiKey());

        return Cache::remember($cacheKey, now()->addMinutes(10), function () {
            $response = Http::asForm()->withOptions(self::httpOptions())->timeout(20)->post($this->cfg('merchant_info_url'), [
                'key' => $this->apiKey(),
            ]);

            $data = $response->json() ?? [];

            if (!$response->successful() or ($data['error_code'] ?? null) !== '0000') {
                Log::warning('[Espay][' . $this->getModeLabel() . '] merchantinfo failed', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                throw new RuntimeException('Gagal mengambil daftar metode pembayaran Espay: ' . ($data['error_message'] ?? 'HTTP ' . $response->status()));
            }

            return $data['data'] ?? [];
        });
    }

    /**
     * Direct API Virtual Account (non-SNAP) - sendinvoice. Dipakai untuk VA Static Open.
     * Signature (Hash-Based): sha256(UPPER("##key##rq_uuid##rq_datetime##order_id##amount##ccy##comm_code##SENDINVOICE##")).
     * amount kosong = open amount. bank_code kosong = VA untuk semua bank (va_list).
     */
    public function sendInvoice(array $params, ?string $signatureOverride = null): array
    {
        $signatureKey = (string) $this->cfg('signature_key');

        if ($signatureKey === '') {
            throw new RuntimeException('Signature key Espay belum diatur (Admin > Payment Channels > Espay atau .env).');
        }

        $params = array_merge([
            'rq_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'rq_datetime' => now()->format('Y-m-d H:i:s'),
            'amount' => '',
            'ccy' => 'IDR',
            'comm_code' => $this->merchantCode(),
            'update' => 'N',
        ], $params);

        $params['signature'] = $signatureOverride ?? self::hashSignature($signatureKey, [
            $params['rq_uuid'], $params['rq_datetime'], $params['order_id'], $params['amount'], $params['ccy'], $params['comm_code'], 'SENDINVOICE',
        ]);

        $response = Http::asForm()->withOptions(self::httpOptions())->timeout(30)->post((string) $this->cfg('send_invoice_url'), $params);
        $data = $response->json();

        Log::channel('espay')->info('[VA OUT][' . $this->getModeLabel() . '] sendinvoice', [
            'request' => array_merge($params, ['signature' => '...']),
            'http_status' => $response->status(),
            'response' => $data ?? mb_substr($response->body(), 0, 500),
        ]);

        if (!is_array($data)) {
            throw new RuntimeException('Espay sendinvoice gagal: HTTP ' . $response->status());
        }

        return $data;
    }

    /**
     * Check Payment Status (non-SNAP) berdasarkan order_id sendinvoice (VA Static: transaksi terakhir VA tsb).
     * Signature: sha256(UPPER("##key##rq_datetime##order_id##CHECKSTATUS##")).
     * $isPaymentNotif: "Y" = Espay mengirim ulang payment notification, "N" = tandai Success di dashboard Espay.
     */
    public function checkPaymentStatus(string $orderId, ?string $isPaymentNotif = null): array
    {
        $signatureKey = (string) $this->cfg('signature_key');

        if ($signatureKey === '') {
            throw new RuntimeException('Signature key Espay belum diatur.');
        }

        $rqDatetime = now()->format('Y-m-d H:i:s');
        $params = array_filter([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'rq_datetime' => $rqDatetime,
            'comm_code' => $this->merchantCode(),
            'order_id' => $orderId,
            'is_paymentnotif' => $isPaymentNotif,
            'signature' => self::hashSignature($signatureKey, [$rqDatetime, $orderId, 'CHECKSTATUS']),
        ], fn ($value) => $value !== null);

        $response = Http::asForm()->withOptions(self::httpOptions())->timeout(20)->post((string) $this->cfg('check_status_url'), $params);
        $data = $response->json();

        Log::channel('espay')->info('[VA OUT][' . $this->getModeLabel() . '] checkstatus', [
            'order_id' => $orderId,
            'is_paymentnotif' => $isPaymentNotif,
            'http_status' => $response->status(),
            'response' => $data ?? mb_substr($response->body(), 0, 500),
        ]);

        if (!is_array($data)) {
            throw new RuntimeException('Espay check status gagal: HTTP ' . $response->status());
        }

        return $data;
    }

    /* ---------------- Disbursement (SNAP Transfer) - docs.espay.id > Disbursement ---------------- */

    /**
     * Balance Inquiry (service 11): saldo deposit disbursement.
     */
    public function balanceInquiry(string $partnerReferenceNo): array
    {
        return $this->disbursementRequest('balance_inquiry', [
            'partnerReferenceNo' => $partnerReferenceNo,
            'accountNo' => $this->disbursementSourceAccount(),
        ]);
    }

    /**
     * Bank Statement (service 14): mutasi rekening sumber dana.
     */
    public function bankStatement(string $partnerReferenceNo, string $fromDateTime, string $toDateTime, int $page = 1, int $pageSize = 50): array
    {
        return $this->disbursementRequest('bank_statement', [
            'partnerReferenceNo' => $partnerReferenceNo,
            'accountNo' => $this->disbursementSourceAccount(),
            'fromDateTime' => $fromDateTime,
            'toDateTime' => $toDateTime,
            'additionalInfo' => ['pageNumber' => (string) $page, 'pageSize' => (string) $pageSize],
        ]);
    }

    /**
     * Inquiry Account Internal (15): rekening tujuan di bank yang sama dengan bank sumber.
     */
    public function accountInquiryInternal(string $partnerReferenceNo, string $accountNo): array
    {
        return $this->disbursementRequest('account_inquiry_internal', [
            'partnerReferenceNo' => $partnerReferenceNo,
            'beneficiaryAccountNo' => $accountNo,
            'additionalInfo' => [
                'originatorDetails' => ['accountNo' => $this->disbursementSourceAccount()],
                'sourceBankCode' => $this->disbursementSourceBankCode(),
            ],
        ]);
    }

    /**
     * Inquiry Account External (16): rekening tujuan di bank lain (BI-FAST).
     */
    public function accountInquiryExternal(string $partnerReferenceNo, string $bankCode, string $accountNo, string $amount): array
    {
        return $this->disbursementRequest('account_inquiry_external', [
            'partnerReferenceNo' => $partnerReferenceNo,
            'beneficiaryBankCode' => $bankCode,
            'beneficiaryAccountNo' => $accountNo,
            'additionalInfo' => [
                'amount' => ['value' => $amount, 'currency' => 'IDR'],
                'accountNo' => $this->disbursementSourceAccount(),
                'trxType' => '02',       // BI-FAST
                'proxyValue' => '',
                'proxyType' => '',
                'trxPurposeCode' => (string) config('espay.disbursement.purpose_of_transaction', '99'),
                'transferType' => '5',   // BI-FAST
                'sourceBankCode' => $this->disbursementSourceBankCode(),
                'sourceAccountNo' => $this->disbursementSourceAccount(),
            ],
        ]);
    }

    /**
     * Transfer Intrabank (17) / Interbank BI-FAST (18). Body sesuai dokumentasi, dibentuk oleh EspayDisbursementService.
     */
    public function transfer(array $body, bool $intrabank = false): array
    {
        return $this->disbursementRequest($intrabank ? 'transfer_intrabank' : 'transfer_interbank', $body);
    }

    /**
     * Inquiry Status (36). latestTransactionStatus: 00 sukses, 01 init, 03 pending, 05 batal, 06 gagal.
     */
    public function transferStatus(array $transfer): array
    {
        return $this->disbursementRequest('transfer_status', [
            'originalPartnerReferenceNo' => $transfer['partner_reference_no'],
            'originalReferenceNo' => (string) ($transfer['reference_no'] ?? ''),
            'originalExternalId' => (string) ($transfer['external_id'] ?? ''),
            'serviceCode' => (string) $transfer['service'],
            'transactionDate' => $transfer['transaction_date'],
            'amount' => ['value' => $transfer['amount'], 'currency' => 'IDR'],
            'additionalInfo' => [
                'deviceId' => substr(md5((string) $transfer['partner_reference_no']), 0, 16),
                'channel' => 'web',
                'accountNo' => $this->disbursementSourceAccount(),
                'beneficiaryAccountNo' => (string) ($transfer['beneficiary_account_no'] ?? ''),
                'sourceBankCode' => $this->disbursementSourceBankCode(),
            ],
        ]);
    }

    /**
     * X-EXTERNAL-ID request terakhir (dibutuhkan Inquiry Status sebagai originalExternalId).
     */
    public function lastExternalId(): ?string
    {
        return $this->lastExternalId;
    }

    protected function disbursementRequest(string $pathKey, array $body): array
    {
        return $this->request('POST', (string) config("espay.disbursement.paths.{$pathKey}"), $body, $this->disbursementPartnerId());
    }

    protected function request(string $method, string $relativeUrl, array $body, ?string $partnerId = null): array
    {
        $this->assertConfigured();

        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        $timestamp = self::timestamp();
        $externalId = self::externalId();
        $this->lastExternalId = $externalId;

        $headers = [
            'Content-Type' => 'application/json',
            'X-TIMESTAMP' => $timestamp,
            'X-SIGNATURE' => $this->sign($method, $relativeUrl, $json, $timestamp),
            'X-EXTERNAL-ID' => $externalId,
            'X-PARTNER-ID' => $partnerId ?: $this->merchantCode(),
            'CHANNEL-ID' => config('espay.channel_id', 'ESPAY'),
        ];

        $response = Http::withHeaders($headers)->withOptions(self::httpOptions())->withBody($json, 'application/json')
            ->timeout(30)
            ->send($method, rtrim($this->cfg('api_url'), '/') . $relativeUrl);

        $data = $response->json();

        Log::channel('espay')->info('[SNAP OUT][' . $this->getModeLabel() . "] {$method} {$relativeUrl}", [
            'headers' => [
                'X-TIMESTAMP' => $timestamp,
                'X-SIGNATURE' => $headers['X-SIGNATURE'],
                'X-EXTERNAL-ID' => $externalId,
                'X-PARTNER-ID' => $headers['X-PARTNER-ID'],
                'CHANNEL-ID' => config('espay.channel_id', 'ESPAY'),
            ],
            'body' => $json,
            'http_status' => $response->status(),
            'response_headers' => array_map(fn ($values) => implode(', ', $values), $response->headers()),
            'response_body' => $data ?? mb_substr($response->body(), 0, 500),
        ]);

        if (!is_array($data)) {
            throw new RuntimeException("Espay {$relativeUrl} gagal: HTTP {$response->status()}");
        }

        return $data;
    }

    /**
     * Paksa IPv4: Espay memvalidasi IP whitelist (IPv4), sedangkan server dengan IPv6 akan
     * keluar lewat IPv6 karena sandbox-api/api-merchant.espay.id juga punya alamat AAAA.
     */
    protected static function httpOptions(): array
    {
        return ['force_ip_resolve' => 'v4'];
    }

    /**
     * Path openssl.cnf untuk openssl_pkey_new() (di Windows sering tidak ditemukan otomatis).
     */
    public static function opensslConfig(): array
    {
        foreach ([getenv('OPENSSL_CONF'), dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf'] as $path) {
            if (!empty($path) and is_file($path)) {
                return ['config' => $path];
            }
        }

        return [];
    }

    protected function privateKey()
    {
        return $this->loadKey($this->cfg('private_key'), true);
    }

    protected function espayPublicKey()
    {
        return $this->loadKey($this->cfg('espay_public_key'), false);
    }

    /**
     * Kunci bisa berupa isi PEM langsung atau path file (relatif terhadap base_path()).
     */
    protected function loadKey(?string $value, bool $private)
    {
        if (empty($value)) {
            return null;
        }

        $pem = str_contains($value, '-----BEGIN') ? str_replace('\n', "\n", $value) : null;

        if ($pem === null) {
            $path = preg_match('~^([A-Za-z]:[\\\\/]|/)~', $value) ? $value : base_path($value);
            $pem = is_file($path) ? file_get_contents($path) : null;
        }

        if (empty($pem)) {
            return null;
        }

        $key = $private ? openssl_pkey_get_private($pem) : openssl_pkey_get_public($pem);

        return $key ?: null;
    }

    protected function cfg(string $key): ?string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) ? trim($value, " \t\n\r\0\x0B\"'") : $value;
    }
}
