<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Espay\EspayStaticVaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cadangan Payment Notification VA Static: order Espay yang masih menunggu dicek ke Espay
 * (Check Payment Status). Pembayaran S / SP yang belum tercatat langsung diproses.
 */
class EspayVaSync extends Command
{
    protected $signature = 'espay:va-sync {--days=3 : Order menunggu pembayaran dalam N hari terakhir}';

    protected $description = 'Cek pembayaran VA Static Espay yang notifikasinya belum masuk';

    public function handle()
    {
        $since = now()->subDays((int) $this->option('days'))->timestamp;

        // pengguna yang punya order Espay menunggu: semua VA miliknya dicek (bisa bayar ke VA bank lain)
        $userIds = Order::query()
            ->whereIn('status', [Order::$pending, Order::$paying])
            ->where('payment_data', 'like', '%"gateway":"Espay"%')
            ->where('created_at', '>=', $since)
            ->distinct()
            ->pluck('user_id');

        // + VA yang baru dibuat / diperbarui (transfer langsung tanpa order = top up)
        $vas = DB::table('espay_virtual_accounts')
            ->whereIn('user_id', $userIds)
            ->orWhere('updated_at', '>=', $since)
            ->orWhere('created_at', '>=', $since)
            ->get();

        $service = app(EspayStaticVaService::class);

        foreach ($vas as $va) {
            $result = $service->syncFromEspay($va, true);

            if (!empty($result)) {
                $this->info("VA {$va->va_number}: {$result['result']} -> order #{$result['order']->id}");
            }
        }

        $this->line('VA dicek: ' . $vas->count());

        return self::SUCCESS;
    }
}
