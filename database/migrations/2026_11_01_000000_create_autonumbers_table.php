<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The counters behind autonumbered fields (a param's json_config.autonumber). One row per
 * table column and the text around the number: "PO-261021" + 001 and "SPO-261021" + 001
 * count separately, and a format with the date in it starts again at 1 each day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autonumbers', function (Blueprint $table) {
            $table->id();
            $table->string('table_name', 64);
            $table->string('column_name', 64);
            $table->string('prefix', 100)->default('');
            $table->string('suffix', 100)->default('');
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();
            $table->unique(['table_name', 'column_name', 'prefix', 'suffix']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autonumbers');
    }
};
