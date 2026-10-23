<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An external service a grid or form reads and writes instead of a table (see
 * App\Support\ExternalService): its REST address, headers, list query, where the records sit
 * in its answers, and how it updates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metas', function (Blueprint $table) {
            $table->string('service_url', 2048)->nullable();
            $table->text('service_headers')->nullable();
            $table->text('service_query')->nullable();
            $table->string('service_list_path')->nullable();
            $table->string('service_total_path')->nullable();
            $table->string('service_record_path')->nullable();
            $table->string('service_update_method', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('metas', function (Blueprint $table) {
            $table->dropColumn(['service_url', 'service_headers', 'service_query', 'service_list_path', 'service_total_path', 'service_record_path', 'service_update_method']);
        });
    }
};
