<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom session values (see App\Support\SessionValues): path is the name a query or default
 * uses (e.g. sessionemployeeid), query a SELECT whose first column of the first row is the
 * value, param the :name it is given (userid, or an earlier session value's path).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_values', function (Blueprint $table) {
            $table->id();
            $table->string('path', 100)->unique();
            $table->text('query');
            $table->string('param', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_values');
    }
};
