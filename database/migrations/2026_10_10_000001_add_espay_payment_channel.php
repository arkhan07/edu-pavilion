<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Daftarkan channel Espay di Admin > Settings > Financial > Payment Gateways.
 * Dibuat nonaktif; aktifkan setelah kredensial diisi. Tidak mengubah apa pun bila baris Espay sudah ada.
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('payment_channels') or DB::table('payment_channels')->where('class_name', 'Espay')->exists()) {
            return;
        }

        DB::table('payment_channels')->insert([
            'title' => 'Espay (Virtual Account & QRIS)',
            'class_name' => 'Espay',
            'status' => 'inactive',
            'image' => '/assets/default/img/payment/espay.svg',
            'credentials' => null,
            'currencies' => json_encode(['IDR']),
            'created_at' => time(),
        ]);
    }

    public function down()
    {
        // sengaja tidak menghapus: baris berisi kredensial yang diisi admin
    }
};
