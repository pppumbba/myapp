<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('meta_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
        });

        Schema::create('metas', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('label_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->foreignId('ref_meta_id')->nullable()->constrained('metas')->nullOnDelete();
            $table->text('description')->nullable();
            $table->json('json_config')->nullable();
            $table->timestamps();
            $table->foreignId('type_id')->nullable()->constrained('meta_types');
        });


        Schema::create('meta_params', function (Blueprint $table) {
            $table->id();
            $table->integer('order_number');
            $table->string('field_path');
            $table->string('param_name');
            $table->string('column_path')->nullable();
            $table->string('label_name')->nullable();
            $table->string('data_type');
            $table->string('data_mask')->nullable();
            $table->string('render_type')->nullable();
            $table->string('icon_cls')->nullable();
            $table->foreignId('main_meta_id')->constrained('metas')->cascadeOnDelete();
            $table->foreignId('ref_meta_id')->nullable()->constrained('metas')->nullOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_show')->default(true);
            $table->boolean('is_required')->default(false);
            $table->boolean('is_criteria')->default(false);
            $table->boolean('is_unique')->default(false);
            $table->boolean('is_merge')->default(false);
            $table->boolean('is_select')->default(false);
            $table->boolean('is_mandatory_criteria')->default(false);
            $table->string('default_value')->nullable();
            $table->string('min_value')->nullable();
            $table->string('max_value')->nullable();
            $table->string('column_width')->nullable();
            $table->string('expression_string')->nullable();
            $table->json('json_config')->nullable();
            $table->timestamps();
        });


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meta_params');
        Schema::dropIfExists('metas');
        Schema::dropIfExists('meta_types');

    }
};
