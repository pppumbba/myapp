<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables behind the Calendar demo app. The app itself is described by metas
 * (see CalendarDemoSeeder); these hold its data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cal_rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('floor')->nullable();
            $table->integer('capacity')->default(4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cal_events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('category')->default('meeting');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->foreignId('room_id')->nullable()->constrained('cal_rooms');
            $table->string('organizer')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cal_events');
        Schema::dropIfExists('cal_rooms');
    }
};
