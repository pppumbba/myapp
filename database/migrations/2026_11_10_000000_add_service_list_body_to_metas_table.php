<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A service whose list is one POST with a JSON body (an RPC endpoint, e.g. runJson with a
 * command) instead of GET with a query string (see App\Support\ExternalService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metas', function (Blueprint $table) {
            $table->text('service_list_body')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('metas', function (Blueprint $table) {
            $table->dropColumn('service_list_body');
        });
    }
};
