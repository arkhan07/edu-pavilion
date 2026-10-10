<?php

namespace App\Console\Commands;

use App\Services\Espay\EspayClient;
use App\Services\Espay\EspayPaymentService;
use App\Services\Espay\EspayStaticVaService;
use App\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Menjalankan skenario UAT "Send Invoice" (dokumen UAT Script ESPAY - Static) ke Espay SANDBOX
 * dan menyimpan request/response ke storage/app/espay/uat/ sebagai bukti.
 */
class EspayUatSendInvoice extends Command
{
    protected $signature = 'espay:uat-sendinvoice {user : ID user untuk VA uji} {--bank=002 : Kode bank}';

    protected $description = 'Jalankan skenario UAT Send Invoice (positif & negatif) ke Espay sandbox';

    public function handle()
    {
        $client = new EspayClient();

        if ($client->isProduction()) {
            $this->error('Hanya untuk SANDBOX.');
            return self::FAILURE;
        }

        $user = User::find($this->argument('user'));
        if (empty($user)) {
            $this->error('User tidak ditemukan.');
            return self::FAILURE;
        }

        $bank = (string) $this->option('bank');
        $base = [
            'order_id' => EspayStaticVaService::orderIdFor($user->id, $bank),
            'remark1' => EspayPaymentService::phone($user->mobile ?? '', 17) ?: '081234567890',
            'remark2' => 'Edu Pavilion UAT',
            'remark3' => (string) ($user->email ?: 'uat@example.com'),
            'remark4' => (string) $user->id,
            'bank_code' => $bank,
            'va_expired' => (string) config('espay.static_va_expired_minutes', 525600),
            'update' => 'Y',
        ];

        $cases = [
            ['1', 'Positif: send invoice valid', [], null, '0000/00 + va_number'],
            ['2', 'Negatif: order_id dikosongkan', ['order_id' => ''], null, '0001'],
            ['3', 'Negatif: signature salah', [], hash('sha256', Str::random(40)), 'IRF Credential Is Not Valid'],
            ['4', 'Negatif: order_id format salah (simbol)', ['order_id' => 'EDU-#@!' . $user->id], null, '0004'],
            ['5', 'Negatif: ccy bukan IDR', ['ccy' => 'SGD'], null, '0003 Currency'],
            ['6', 'Negatif: comm_code tidak terdaftar', ['comm_code' => 'SGWUATTIDAKADA'], null, '0003 Community / IP rejected'],
            ['7', 'Negatif: amount 0 order_id sama (khusus static close)', ['amount' => '0'], null, '0044 (N/A untuk Static Open)'],
            ['8', 'Negatif: remark1 karakter spesial', ['remark1' => '0812#$%^&*'], null, '0004 remark1'],
            ['9', 'Positif ulang: VA tetap aktif (open amount) setelah uji negatif', [], null, '0000/00 + va_number'],
        ];

        $results = [];
        foreach ($cases as [$no, $label, $override, $signature, $expected]) {
            $params = array_merge($base, ['rq_uuid' => (string) Str::uuid(), 'rq_datetime' => now()->format('Y-m-d H:i:s')], $override);

            try {
                $response = $client->sendInvoice($params, $signature);
            } catch (\Throwable $e) {
                $response = ['exception' => $e->getMessage()];
            }

            $results[] = [
                'no' => $no,
                'case' => $label,
                'expected' => $expected,
                'request' => array_merge($params, ['signature' => $signature ? '(sengaja salah)' : '(valid)']),
                'response' => $response,
                'at' => now()->toDateTimeString(),
            ];

            $this->line("[{$no}] {$label}");
            $this->line('    expected: ' . $expected);
            $this->line('    response: ' . json_encode($response, JSON_UNESCAPED_SLASHES));
            sleep(1);
        }

        // storage/app (privat) - disk "local" proyek ini mengarah ke public/store
        $path = storage_path('app/espay/uat/sendinvoice-' . now()->format('Ymd-His') . '.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info('Hasil disimpan: ' . $path);

        return self::SUCCESS;
    }
}
