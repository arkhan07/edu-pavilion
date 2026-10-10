<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * espay_va_payments: trx_id = ID transaksi Espay (ESP...), payment_ref = referensi pembayaran Espay.
 * Baris lama hasil Check Payment Status menyimpan payment_ref di trx_id -> dipindah.
 */
return new class extends Migration {
    public function up()
    {
        if (!Schema::hasColumn('espay_va_payments', 'payment_ref')) {
            Schema::table('espay_va_payments', function (Blueprint $table) {
                $table->string('payment_ref', 64)->nullable()->after('trx_id')->index();
            });
        }

        foreach (DB::table('espay_va_payments')->whereNull('payment_ref')->get() as $row) {
            $data = json_decode((string) $row->data, true) ?: [];
            $txId = (string) data_get($data, 'check_status.tx_id', '');
            $paymentRef = (string) data_get($data, 'additionalInfo.paymentRef', '');

            $update = ['payment_ref' => $paymentRef !== '' ? mb_substr($paymentRef, 0, 64) : null];
            if ($txId !== '' and $txId !== $row->trx_id and !DB::table('espay_va_payments')->where('trx_id', $txId)->exists()) {
                $update['trx_id'] = mb_substr($txId, 0, 64);
            }

            DB::table('espay_va_payments')->where('id', $row->id)->update($update);
        }
    }

    public function down()
    {
        if (Schema::hasColumn('espay_va_payments', 'payment_ref')) {
            Schema::table('espay_va_payments', function (Blueprint $table) {
                $table->dropIndex(['payment_ref']);
                $table->dropColumn('payment_ref');
            });
        }
    }
};
