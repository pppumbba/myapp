<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_params', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('main_meta_id')
                ->constrained('meta_params')
                ->cascadeOnDelete();

            $table->index(['main_meta_id', 'parent_id', 'order_number']);
        });
    }

    public function down(): void
    {
        Schema::table('meta_params', function (Blueprint $table) {
            $table->dropIndex(['main_meta_id', 'parent_id', 'order_number']);
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
