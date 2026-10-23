<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The EAM demo's finance side: an asset's balances, the transactions that change them
 * (capitalization, depreciation, revaluation, devaluation) and the journal they post.
 *
 * A transaction is saved as a draft; posting it (stage query eam_post_transaction) works out
 * its amounts from the asset's balances, moves the balances and writes its journal lines
 * from eam_posting_rules. Posted transactions and their lines can't be deleted.
 *
 *   carrying_amount = capitalized_cost + revaluation_amount
 *                     - accumulated_depreciation - accumulated_impairment
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eam_assets', function (Blueprint $table) {
            $table->date('capitalized_on')->nullable();
            $table->decimal('capitalized_cost', 14, 2)->default(0);
            $table->decimal('revaluation_amount', 14, 2)->default(0);
            $table->decimal('accumulated_depreciation', 14, 2)->default(0);
            $table->decimal('accumulated_impairment', 14, 2)->default(0);
            $table->decimal('revaluation_surplus', 14, 2)->default(0);
            $table->decimal('carrying_amount', 14, 2)->virtualAs('capitalized_cost + revaluation_amount - accumulated_depreciation - accumulated_impairment');
        });

        Schema::create('eam_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('kind');
            $table->timestamps();
        });

        // Which accounts each part of a transaction goes to. part: amount (the whole amount),
        // reserve (the share that goes to or from the revaluation surplus) or pl (to profit/loss).
        Schema::create('eam_posting_rules', function (Blueprint $table) {
            $table->id();
            $table->string('txn_type');
            $table->string('part');
            $table->string('debit_account');
            $table->string('credit_account');
            $table->string('description')->nullable();
            $table->timestamps();
            $table->unique(['txn_type', 'part']);
        });

        Schema::create('eam_asset_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('txn_no')->unique();
            $table->string('txn_type');
            $table->foreignId('asset_id')->constrained('eam_assets');
            $table->foreignId('work_order_id')->nullable()->constrained('eam_work_orders')->nullOnDelete();
            $table->date('txn_date');
            $table->string('period', 7)->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('new_value', 14, 2)->nullable();
            $table->string('offset_account')->nullable();
            $table->decimal('book_value_before', 14, 2)->nullable();
            $table->decimal('reserve_amount', 14, 2)->default(0);
            $table->decimal('pl_amount', 14, 2)->default(0);
            $table->decimal('book_value_after', 14, 2)->nullable();
            $table->string('status')->default('draft');
            $table->date('posted_on')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->index(['asset_id', 'txn_type', 'period']);
        });

        Schema::create('eam_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('eam_asset_transactions');
            $table->foreignId('asset_id')->constrained('eam_assets');
            $table->date('entry_date');
            $table->string('account_code');
            $table->string('txn_type');
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->string('memo')->nullable();
            $table->timestamps();
            $table->index(['account_code', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eam_journal_entries');
        Schema::dropIfExists('eam_asset_transactions');
        Schema::dropIfExists('eam_posting_rules');
        Schema::dropIfExists('eam_accounts');
        Schema::table('eam_assets', function (Blueprint $table) {
            $table->dropColumn(['carrying_amount', 'capitalized_on', 'capitalized_cost', 'revaluation_amount', 'accumulated_depreciation', 'accumulated_impairment', 'revaluation_surplus']);
        });
    }
};
