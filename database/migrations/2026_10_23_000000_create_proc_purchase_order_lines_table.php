<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The lines of a purchase order, edited as the order form's details. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proc_purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('proc_purchase_orders')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('proc_items');
            $table->string('description')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proc_purchase_order_lines');
    }
};
