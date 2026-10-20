<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables behind the Learning (LMS) demo app. The app itself is described by metas
 * (see LmsDemoSeeder); these hold its data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_instructors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('expertise')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('lms_courses', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->string('category')->nullable();
            $table->string('level')->default('beginner');
            $table->foreignId('instructor_id')->nullable()->constrained('lms_instructors');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->integer('capacity')->default(20);
            $table->string('status')->default('draft');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('lms_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->string('title');
            $table->string('kind')->default('video');
            $table->integer('duration_minutes')->default(30);
            $table->timestamps();
        });

        Schema::create('lms_students', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('department')->nullable();
            $table->date('joined_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('lms_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('lms_students');
            $table->foreignId('course_id')->constrained('lms_courses');
            $table->date('enrolled_on');
            $table->integer('progress')->default(0);
            $table->string('status')->default('enrolled');
            $table->decimal('grade', 5, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('lms_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses');
            $table->string('title');
            $table->string('kind')->default('lecture');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('location')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_sessions');
        Schema::dropIfExists('lms_enrollments');
        Schema::dropIfExists('lms_students');
        Schema::dropIfExists('lms_lessons');
        Schema::dropIfExists('lms_courses');
        Schema::dropIfExists('lms_instructors');
    }
};
