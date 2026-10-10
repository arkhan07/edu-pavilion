<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pencairan dana via gateway yang asinkron (Espay Disbursement):
 * status `processing` = saldo sudah dipotong, menunggu hasil transfer dari gateway.
 */
return new class extends Migration {
    public function up()
    {
        DB::statement("ALTER TABLE `payouts` MODIFY `status` ENUM('waiting','processing','done','reject') NOT NULL");

        Schema::table('payouts', function (Blueprint $table) {
            if (!Schema::hasColumn('payouts', 'provider')) {
                $table->string('provider', 20)->nullable()->after('is_automatic');
            }
            if (!Schema::hasColumn('payouts', 'provider_reference')) {
                $table->string('provider_reference', 64)->nullable()->after('provider')->index();
            }
            if (!Schema::hasColumn('payouts', 'provider_status')) {
                $table->string('provider_status', 30)->nullable()->after('provider_reference');
            }
            if (!Schema::hasColumn('payouts', 'provider_data')) {
                $table->text('provider_data')->nullable()->after('provider_status');
            }
            if (!Schema::hasColumn('payouts', 'processed_at')) {
                $table->unsignedInteger('processed_at')->nullable()->after('created_at');
            }
        });
    }

    public function down()
    {
        DB::table('payouts')->where('status', 'processing')->update(['status' => 'waiting']);
        DB::statement("ALTER TABLE `payouts` MODIFY `status` ENUM('waiting','done','reject') NOT NULL");

        Schema::table('payouts', function (Blueprint $table) {
            $table->dropIndex(['provider_reference']);
            $table->dropColumn(['provider', 'provider_reference', 'provider_status', 'provider_data', 'processed_at']);
        });
    }
};
