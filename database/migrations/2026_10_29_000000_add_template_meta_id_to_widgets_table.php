<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite widgets: a library entry drawn by the composite renderer is made of other
 * widgets. Its parts are laid out like a page, on a widget_template meta whose params place
 * widget metas; template_meta_id points at that meta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widgets', function (Blueprint $table) {
            $table->foreignId('template_meta_id')->nullable()->after('renderer')->constrained('metas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('widgets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('template_meta_id');
        });
    }
};
