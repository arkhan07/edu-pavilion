<?php

namespace App\Console\Commands;

use App\Models\Payout;
use App\Services\Espay\EspayClient;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Simulasi callback Espay Disbursement (Transfer Confirmation / Notification) ke endpoint lokal.
 * Sama seperti espay:simulate: ditandatangani dengan kunci sementara (sandbox saja).
 */
class EspaySimulateTransfer extends Command
{
    protected $signature = 'espay:simulate-transfer
                            {payout : ID payout}
                            {--type=notification : confirmation | notification}
                            {--status=00 : latestTransactionStatus untuk notification (00 sukses, 01 init, 03 pending, 05 batal, 06 gagal)}
                            {--amount= : Nominal (default = nominal payout)}
                            {--reference= : partnerReferenceNo (default = milik payout)}
                            {--invalid-signature : Kirim signature yang salah}';

    protected $description = 'Simulasikan callback Transfer Confirmation / Notification Espay ke endpoint lokal';

    public function handle()
    {
        if ((new EspayClient())->isProduction()) {
            $this->error('Simulasi hanya boleh di mode SANDBOX.');
            return self::FAILURE;
        }

        $payout = Payout::find($this->argument('payout'));
        if (empty($payout) or empty($payout->provider_reference)) {
            $this->error('Payout tidak ditemukan atau bukan pencairan Espay.');
            return self::FAILURE;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + EspayClient::opensslConfig());
        EspayClient::$overrides = ['espay_public_key' => openssl_pkey_get_details($key)['key']];

        $client = new EspayClient();
        $isConfirmation = $this->option('type') === 'confirmation';
        $path = '/payments/espay/v1.0/transfer/' . ($isConfirmation ? 'confirmation' : 'notification');
        $now = EspayClient::timestamp();
        $data = $payout->providerData();

        $reference = $this->option('reference') ?: $payout->provider_reference;
        $amount = ['value' => number_format((float) ($this->option('amount') ?: $payout->amount), 2, '.', ''), 'currency' => 'IDR'];

        // Format sesuai contoh di docs.espay.id > Disbursement > Transfer Confirmation / Transfer Notification
        $body = $isConfirmation ? [
            'partnerReferenceNo' => $reference,
            'merchantId' => $client->disbursementPartnerId(),
            'amount' => $amount,
            'sourceAccountNo' => hash('sha256', $client->disbursementSourceAccount() . $reference),
            'sourceBankCode' => hash('sha256', $client->disbursementSourceBankCode() . $reference),
        ] : [
            'originalPartnerReferenceNo' => $reference,
            'originalReferenceNo' => $data['reference_no'] ?? ('ESP' . strtoupper(Str::random(12))),
            'originalExternalId' => $data['external_id'] ?? EspayClient::externalId(),
            'latestTransactionStatus' => $this->option('status'),
            'amount' => $amount,
            'beneficiaryAccountNo' => $data['beneficiary']['account_no'] ?? '',
            'beneficiaryBankCode' => $data['beneficiary']['swift'] ?? '',
            'transactionDate' => $now,
            'additionalInfo' => ['bankReferenceNo' => ''],
        ];

        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        openssl_sign(EspayClient::stringToSign('POST', $path, $json, $now), $signature, $key, OPENSSL_ALGO_SHA256);
        $signature = $this->option('invalid-signature') ? base64_encode(random_bytes(256)) : base64_encode($signature);

        $response = app()->handle(Request::create($path, 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TIMESTAMP' => $now,
            'HTTP_X_SIGNATURE' => $signature,
            'HTTP_X_EXTERNAL_ID' => EspayClient::externalId(),
            'HTTP_X_PARTNER_ID' => $client->disbursementPartnerId(),
            'HTTP_CHANNEL_ID' => 'ESPAY',
        ], $json));

        $this->line("POST {$path} -> HTTP {$response->getStatusCode()}");
        $this->line($response->getContent());
        $payout->refresh();
        $this->info("Payout #{$payout->id}: {$payout->status} ({$payout->provider_status})");

        return self::SUCCESS;
    }
}
