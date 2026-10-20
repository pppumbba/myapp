<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roles, who has them, what each role may do with a meta and with a workflow status, and
 * the notifications people receive.
 *
 * The "admin" role sees and changes everything; every user that exists now gets it.
 * A meta or status without permission rows stays open to everyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->primary(['user_id', 'role_id']);
        });

        Schema::create('meta_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_id')->constrained('metas')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->boolean('can_view')->default(true);
            $table->boolean('can_create')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->unique(['meta_id', 'role_id']);
        });

        Schema::create('wfm_status_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_id')->constrained('wfm_status')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            // May move a record into this status.
            $table->boolean('can_enter')->default(false);
            // May see records while they are in this status.
            $table->boolean('can_see')->default(false);
            $table->unique(['status_id', 'role_id']);
        });

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // info, success, alert or danger.
            $table->string('type', 20)->default('info');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('link')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['user_id', 'read_at']);
        });

        $now = now();
        $admin = DB::table('roles')->insertGetId([
            'code' => 'admin', 'name' => 'Administrator', 'description' => 'Sees and changes everything, including roles and permissions.',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('user_roles')->insertUsing(['user_id', 'role_id'], DB::table('users')->select('id', DB::raw((int) $admin)));
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
        Schema::dropIfExists('wfm_status_permissions');
        Schema::dropIfExists('meta_permissions');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('roles');
    }
};
