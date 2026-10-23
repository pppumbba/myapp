<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tags say what a meta is for ("Procurement", "Finance", "Report"); a meta can have several
 * (meta_tag_map). Anyone signed in can add a tag; see App\Models\Tag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->string('color', 20)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('meta_tag_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_id')->constrained('metas')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['meta_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_tag_map');
        Schema::dropIfExists('tags');
    }
};
