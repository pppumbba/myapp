<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables behind the Projects (gantt) demo app. The app itself is described by metas
 * (see GanttDemoSeeder); these hold its data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('owner')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('pm_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('pm_projects');
            $table->string('name');
            $table->string('assignee')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('progress')->default(0);
            $table->string('status')->default('planned');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_tasks');
        Schema::dropIfExists('pm_projects');
    }
};
