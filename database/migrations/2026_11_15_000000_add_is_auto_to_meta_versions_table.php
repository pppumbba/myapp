<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versions saved by themselves before a change (see App\Http\Middleware\SavesMetaVersion),
 * told apart from the ones people save with a note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_versions', function (Blueprint $table) {
            $table->boolean('is_auto')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('meta_versions', function (Blueprint $table) {
            $table->dropColumn('is_auto');
        });
    }
};
