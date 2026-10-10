<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Bersihkan file cache & session kadaluarsa tiap hari jam 03:00 — mencegah penuhi disk & Bad Handle
        $schedule->command('cache:prune-files --days=7')->dailyAt('03:00')->withoutOverlapping()->onOneServer();
        // Alternatif bawaan Laravel untuk session file driver (lottery 2/100 kadang tidak cukup di traffic tinggi)
        // Prune juga view compiled yang sudah lama

        // Pencairan dana Espay yang belum mendapat Transfer Notification: cek status ke Espay
        $schedule->command('espay:payout-sync')->everyTenMinutes()->withoutOverlapping()->onOneServer();
        // Pembayaran VA Static yang notifikasinya tidak sampai (Suspect): cek ke Espay
        $schedule->command('espay:va-sync')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
