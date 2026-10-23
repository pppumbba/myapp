<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchases, sales and disposals in the EAM ledger. A sale or disposal takes the asset off
 * the books: posting keeps what it removed (cost, depreciation, impairment) on the
 * transaction, so the journal can clear them through the disposal account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eam_asset_transactions', function (Blueprint $table) {
            $table->string('counterparty')->nullable();
            $table->string('invoice_no')->nullable();
            $table->decimal('cost_out', 14, 2)->default(0);
            $table->decimal('depreciation_out', 14, 2)->default(0);
            $table->decimal('impairment_out', 14, 2)->default(0);
        });

        Schema::table('eam_assets', function (Blueprint $table) {
            $table->date('disposed_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('eam_assets', function (Blueprint $table) {
            $table->dropColumn('disposed_on');
        });
        Schema::table('eam_asset_transactions', function (Blueprint $table) {
            $table->dropColumn(['counterparty', 'invoice_no', 'cost_out', 'depreciation_out', 'impairment_out']);
        });
    }
};
