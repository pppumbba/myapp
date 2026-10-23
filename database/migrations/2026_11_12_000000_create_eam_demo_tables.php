<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Enterprise Asset Management demo: the company's assets, where they are and who has
 * them, the work done on them (work orders and the parts they used), preventive
 * maintenance plans that raise work orders when due, meter readings and transfers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eam_locations', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('kind')->default('building');
            $table->string('address')->nullable();
            $table->timestamps();
        });

        Schema::create('eam_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('useful_life_years')->default(5);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('eam_assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_tag')->unique();
            $table->string('name');
            $table->foreignId('category_id')->nullable()->constrained('eam_categories')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('eam_locations')->nullOnDelete();
            $table->string('serial_no')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_cost', 14, 2)->default(0);
            $table->decimal('salvage_value', 14, 2)->default(0);
            $table->integer('useful_life_years')->nullable();
            $table->date('warranty_until')->nullable();
            $table->string('status')->default('in service');
            $table->string('condition')->default('good');
            $table->string('criticality')->default('medium');
            $table->string('department')->nullable();
            $table->string('assigned_to')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('eam_pm_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('eam_assets')->cascadeOnDelete();
            $table->string('title');
            $table->string('type')->default('preventive');
            $table->string('priority')->default('medium');
            $table->integer('interval_days')->default(30);
            $table->date('next_due');
            $table->date('last_done')->nullable();
            $table->string('technician')->nullable();
            $table->text('task_list')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('eam_work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('wo_no')->unique();
            $table->foreignId('asset_id')->constrained('eam_assets')->cascadeOnDelete();
            $table->foreignId('pm_plan_id')->nullable()->constrained('eam_pm_plans')->nullOnDelete();
            $table->string('title');
            $table->string('type')->default('corrective');
            $table->string('priority')->default('medium');
            $table->string('status')->default('open');
            $table->string('reported_by')->nullable();
            $table->string('technician')->nullable();
            $table->date('reported_on')->nullable();
            $table->dateTime('scheduled_start')->nullable();
            $table->dateTime('scheduled_end')->nullable();
            $table->date('completed_on')->nullable();
            $table->decimal('labor_hours', 8, 2)->default(0);
            $table->decimal('labor_cost', 14, 2)->default(0);
            $table->decimal('parts_cost', 14, 2)->default(0);
            $table->decimal('total_cost', 14, 2)->storedAs('labor_cost + parts_cost');
            $table->text('description')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamps();
        });

        Schema::create('eam_work_order_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('eam_work_orders')->cascadeOnDelete();
            $table->string('part_name');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('eam_meter_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('eam_assets')->cascadeOnDelete();
            $table->date('read_on');
            $table->string('meter')->default('hours');
            $table->decimal('value', 14, 2);
            $table->string('read_by')->nullable();
            $table->timestamps();
        });

        Schema::create('eam_asset_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('eam_assets')->cascadeOnDelete();
            $table->foreignId('from_location_id')->nullable()->constrained('eam_locations')->nullOnDelete();
            $table->foreignId('to_location_id')->constrained('eam_locations');
            $table->date('moved_on');
            $table->string('assigned_to')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eam_asset_transfers');
        Schema::dropIfExists('eam_meter_readings');
        Schema::dropIfExists('eam_work_order_parts');
        Schema::dropIfExists('eam_work_orders');
        Schema::dropIfExists('eam_pm_plans');
        Schema::dropIfExists('eam_assets');
        Schema::dropIfExists('eam_categories');
        Schema::dropIfExists('eam_locations');
    }
};
