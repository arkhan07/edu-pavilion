<?php

namespace App\Console\Commands;

use App\Http\Controllers\EspayNotificationController;
use App\Services\Espay\EspayClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Skenario negatif UAT Payment Notification: merchant sengaja memberi response salah
 * agar transaksi di portal Espay berstatus Suspect (PR31). Hanya berlaku di SANDBOX.
 */
class EspayUatFault extends Command
{
    protected $signature = 'espay:uat-fault {mode? : signature | signature_component | password | format | off}';

    protected $description = 'Aktifkan/nonaktifkan skenario negatif UAT untuk Payment Notification Espay (sandbox)';

    public function handle()
    {
        $mode = $this->argument('mode');

        if ($mode === null) {
            $current = Cache::get(EspayNotificationController::UAT_FAULT_CACHE_KEY);
            $this->line('Mode sekarang: ' . ($current ?: 'off (normal)'));
            foreach (EspayNotificationController::UAT_FAULTS as $key => $label) {
                $this->line("  {$key}: {$label}");
            }

            return self::SUCCESS;
        }

        if ($mode === 'off') {
            Cache::forget(EspayNotificationController::UAT_FAULT_CACHE_KEY);
            $this->info('Mode UAT dimatikan, notifikasi diproses normal.');

            return self::SUCCESS;
        }

        if ((new EspayClient())->isProduction()) {
            $this->error('Hanya untuk SANDBOX.');
            return self::FAILURE;
        }

        if (!isset(EspayNotificationController::UAT_FAULTS[$mode])) {
            $this->error('Mode tidak dikenal.');
            return self::FAILURE;
        }

        // otomatis mati setelah 2 jam agar tidak tertinggal aktif
        Cache::put(EspayNotificationController::UAT_FAULT_CACHE_KEY, $mode, now()->addHours(2));
        $this->info("Mode UAT '{$mode}' aktif (2 jam): " . EspayNotificationController::UAT_FAULTS[$mode]);
        $this->warn('Selama aktif, notifikasi pembayaran TIDAK diproses. Matikan dengan: php artisan espay:uat-fault off');

        return self::SUCCESS;
    }
}
