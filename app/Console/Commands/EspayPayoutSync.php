<?php

namespace App\Console\Commands;

use App\Models\Payout;
use App\Services\Espay\EspayDisbursementService;
use Illuminate\Console\Command;

/**
 * 1. Proses ulang pencairan yang ditahan karena saldo deposit Espay kurang / gagal dicek.
 * 2. Fallback bila Transfer Notification dari Espay tidak masuk: cek status pencairan
 *    yang masih `processing` via Transfer Status Inquiry (service 36).
 */
class EspayPayoutSync extends Command
{
    protected $signature = 'espay:payout-sync {--payout= : Hanya cek ID payout tertentu} {--all : Abaikan batas waktu tunggu}';

    protected $description = 'Cek status pencairan dana Espay yang masih diproses';

    public function handle()
    {
        $service = app(EspayDisbursementService::class);

        if (!$this->option('payout')) {
            foreach ($service->retryHeld() as $id => $result) {
                $this->line("#{$id} (antrean deposit): {$result}");
            }
        }

        $query = Payout::query()
            ->where('provider', EspayDisbursementService::PROVIDER)
            ->where('status', Payout::$processing);

        if ($this->option('payout')) {
            $query->where('id', $this->option('payout'));
        } elseif (!$this->option('all')) {
            $query->where('created_at', '<', time() - 60 * (int) config('espay.disbursement.status_check_after_minutes', 15));
        }

        foreach ($query->orderBy('id')->limit(100)->get() as $payout) {
            try {
                $payout = $service->syncStatus($payout);
                $this->line("#{$payout->id} {$payout->provider_reference}: {$payout->status} ({$payout->provider_status})");
            } catch (\Throwable $e) {
                $this->error("#{$payout->id}: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
