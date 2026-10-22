<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A form's scripts, run in the browser while it is filled in (see the frontend's formScript):
 * on load, on every change, before and after saving, and shared variables and functions.
 */
return new class extends Migration
{
    public const COLUMNS = [
        'load_expression_string',
        'event_expression_string',
        'before_save_expression_string',
        'after_save_expression_string',
        'var_fnc_expression_string',
    ];

    public function up(): void
    {
        Schema::table('metas', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                $table->longText($column)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('metas', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
