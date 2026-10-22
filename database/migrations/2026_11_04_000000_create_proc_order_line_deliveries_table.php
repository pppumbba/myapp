<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deliveries of a purchase order line: lines of a line, edited under each order line in the
 * order form. delivered_quantity on the line adds up its deliveries' quantities.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proc_order_line_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_line_id')->constrained('proc_purchase_order_lines')->cascadeOnDelete();
            $table->date('delivery_date');
            $table->integer('quantity')->default(1);
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::table('proc_purchase_order_lines', function (Blueprint $table) {
            $table->integer('delivered_quantity')->default(0)->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('proc_purchase_order_lines', function (Blueprint $table) {
            $table->dropColumn('delivered_quantity');
        });
        Schema::dropIfExists('proc_order_line_deliveries');
    }
};
