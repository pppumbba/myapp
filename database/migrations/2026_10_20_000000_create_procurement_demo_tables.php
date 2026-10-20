<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables behind the Procurement demo app. The app itself is described by metas
 * (see ProcurementDemoSeeder); these hold its data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proc_suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('country')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('proc_items', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('unit')->default('pcs');
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('proc_purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_no')->unique();
            $table->string('title');
            $table->string('requested_by')->nullable();
            $table->string('department')->nullable();
            $table->foreignId('item_id')->nullable()->constrained('proc_items');
            $table->integer('quantity')->default(1);
            $table->date('needed_by')->nullable();
            $table->string('status')->default('draft');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('proc_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number')->unique();
            $table->foreignId('supplier_id')->constrained('proc_suppliers');
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->string('status')->default('draft');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proc_purchase_orders');
        Schema::dropIfExists('proc_purchase_requests');
        Schema::dropIfExists('proc_items');
        Schema::dropIfExists('proc_suppliers');
    }
};
