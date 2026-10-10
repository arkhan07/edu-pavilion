<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espay VA Static Open: satu nomor VA tetap per user per bank (dibuat via sendinvoice),
 * dan log pembayaran yang masuk (trx_id unik -> notifikasi ganda tidak diproses dua kali).
 */
return new class extends Migration {
    public function up()
    {
        if (!Schema::hasTable('espay_virtual_accounts')) {
            Schema::create('espay_virtual_accounts', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id');
                $table->string('bank_code', 3);
                $table->string('order_id', 32)->unique();   // order_id sendinvoice (tetap per user+bank)
                $table->string('va_number', 32)->index();
                $table->unsignedInteger('expired_at')->nullable();
                $table->text('data')->nullable();
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at')->nullable();

                $table->unique(['user_id', 'bank_code']);
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('espay_va_payments')) {
            Schema::create('espay_va_payments', function (Blueprint $table) {
                $table->increments('id');
                $table->string('trx_id', 64)->unique();
                $table->unsignedInteger('espay_virtual_account_id')->nullable();
                $table->unsignedInteger('user_id')->nullable();
                $table->unsignedInteger('order_id')->nullable();   // order yang dilunasi / order top up
                $table->string('va_number', 32);
                $table->decimal('amount', 15, 2);
                $table->string('result', 20);                       // order | topup
                $table->text('data')->nullable();
                $table->unsignedInteger('created_at');

                $table->index('user_id');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('espay_va_payments');
        Schema::dropIfExists('espay_virtual_accounts');
    }
};
