<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forms that run by themselves. A schedule's code says when it runs: a keyword (hourly,
 * daily, weekly, monthly, ...) or a cron expression. Each meta_schedules row runs a form
 * (process_id) once for every row of a datagrid (view_id), each time the schedule is due.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('meta_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('schedules')->cascadeOnDelete();
            $table->foreignId('process_id')->constrained('metas')->cascadeOnDelete();
            $table->foreignId('view_id')->constrained('metas')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_schedules');
        Schema::dropIfExists('schedules');
    }
};
