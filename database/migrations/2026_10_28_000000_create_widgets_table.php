<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The widget library for the page builder. Each row is one kind of widget people can
 * place on a page: which built-in renderer draws it, and its props (the settings it takes,
 * with their defaults). A widget meta (metas.widget_id) is one placed widget, its
 * json_config holding the prop values; a page meta's params place widget metas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('category')->default('Content');
            $table->string('renderer', 50);
            $table->string('icon')->nullable();
            $table->text('description')->nullable();
            // [{key, label, type, default?, options?, hint?, meta_types?, fields?}]
            $table->json('props')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('order_number')->default(0);
            $table->timestamps();
        });

        Schema::table('metas', function (Blueprint $table) {
            $table->foreignId('widget_id')->nullable()->after('type_id')->constrained('widgets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('metas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('widget_id');
        });
        Schema::dropIfExists('widgets');
    }
};
