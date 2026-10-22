<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved versions of metas: a snapshot of the meta and everything defined with it (params,
 * actions, permissions, process flow, a page's widgets, a workflow's statuses), which can be
 * compared and restored. meta_id is not a foreign key, so versions outlive a deleted meta
 * and restoring one brings the meta back under its old id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('meta_id')->index();
            $table->unsignedInteger('version_no');
            // The meta's code and name then, to list versions of deleted metas.
            $table->string('code');
            $table->string('name');
            $table->string('note')->nullable();
            $table->longText('snapshot');
            // Of the snapshot without timestamps: equal hashes mean nothing changed.
            $table->string('hash', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['meta_id', 'version_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_versions');
    }
};
