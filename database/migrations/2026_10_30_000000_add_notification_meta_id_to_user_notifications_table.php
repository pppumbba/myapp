<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications made by a notification meta (see Notifier) remember which one, so its
 * builder can show what it has sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->foreignId('notification_meta_id')->nullable()->after('user_id')->constrained('metas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('notification_meta_id');
        });
    }
};
