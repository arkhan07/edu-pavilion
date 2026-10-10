<?php

namespace App\Console\Commands;

use App\Services\Espay\EspayClient;
use App\Services\Espay\EspayStaticVaService;
use App\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Simulasi Payment Notification non-SNAP (VA Static Open) seperti yang dikirim Espay.
 * Dipakai untuk uji UAT: signature/password salah, notifikasi ganda (payment_ref sama).
 */
class EspaySimulateNotification extends Command
{
    protected $signature = 'espay:simulate-notification
                            {user : ID user pemilik VA}
                            {--bank=002 : Kode bank VA}
                            {--amount=10000 : Nominal dibayar}
                            {--ref= : payment_ref (pakai nilai sama untuk uji double payment)}
                            {--wrong-signature : Kirim signature yang salah}
                            {--wrong-password : Kirim password yang salah}
                            {--url= : Kirim lewat HTTP ke URL ini (default: diproses langsung di aplikasi lokal)}';

    protected $description = 'Simulasikan Payment Notification non-SNAP Espay ke endpoint /payments/espay/notification';

    public function handle()
    {
        $client = new EspayClient();

        if ($client->isProduction()) {
            $this->error('Simulasi hanya boleh di mode SANDBOX.');
            return self::FAILURE;
        }

        $user = User::find($this->argument('user'));
        if (empty($user)) {
            $this->error('User tidak ditemukan.');
            return self::FAILURE;
        }

        $bank = (string) $this->option('bank');
        $va = app(EspayStaticVaService::class)->vaFor($user, $bank);
        $orderId = EspayStaticVaService::orderIdFor($user->id, $bank);
        $rqDatetime = now()->format('Y-m-d H:i:s');

        $params = [
            'rq_uuid' => (string) Str::uuid(),
            'rq_datetime' => $rqDatetime,
            'password' => $this->option('wrong-password') ? 'salah-' . Str::random(6) : (string) $client->notificationPassword(),
            'signature' => $this->option('wrong-signature')
                ? hash('sha256', Str::random(32))
                : EspayClient::hashSignature((string) $client->signatureKey(), [$rqDatetime, $orderId, 'PAYMENTREPORT']),
            'member_id' => $client->merchantCode(),
            'comm_code' => $client->merchantCode(),
            'order_id' => $orderId,
            'ccy' => 'IDR',
            'amount' => number_format((float) $this->option('amount'), 2, '.', ''),
            'debit_from_bank' => $bank,
            'debit_from' => $va['va_number'],
            'debit_from_name' => $user->full_name,
            'credit_to_bank' => $bank,
            'credit_to' => $va['va_number'],
            'credit_to_name' => 'ESPAY AGGREGATOR',
            'product_code' => 'SIMULATOR',
            'message' => '',
            'payment_datetime' => $rqDatetime,
            'payment_ref' => $this->option('ref') ?: 'SIM' . now()->format('ymdHis') . random_int(100, 999),
        ];

        $this->line('VA ' . $va['va_number'] . ' | order_id ' . $orderId . ' | payment_ref ' . $params['payment_ref']);

        if ($url = $this->option('url')) {
            $response = Http::asForm()->timeout(30)->post($url, $params);
            $this->line("POST {$url} -> HTTP {$response->status()}");
            $this->line($response->body());

            return self::SUCCESS;
        }

        $request = Request::create('/payments/espay/notification', 'POST', $params, [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
        $response = app()->handle($request);

        $this->line("POST /payments/espay/notification -> HTTP {$response->getStatusCode()}");
        $this->line($response->getContent());

        return self::SUCCESS;
    }
}
