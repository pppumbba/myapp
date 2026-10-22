<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Tenders demo: a tender with its items, bidders and evaluation KPIs, each bidder's
 * price for each item and score on each KPI. Worked on in one workspace per tender.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenders', function (Blueprint $table) {
            $table->id();
            $table->string('tender_no')->unique();
            $table->string('title');
            $table->decimal('budget', 14, 2)->nullable();
            $table->date('deadline')->nullable();
            $table->string('status')->default('draft');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('tender_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();
            $table->string('name');
            $table->integer('quantity')->default(1);
            $table->string('unit')->default('pcs');
            $table->timestamps();
        });

        Schema::create('tender_bidders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();
            $table->string('name');
            $table->string('contact')->nullable();
            $table->timestamps();
        });

        Schema::create('tender_kpis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();
            $table->string('name');
            $table->decimal('weight', 6, 2)->default(1);
            $table->timestamps();
        });

        Schema::create('tender_bidder_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();
            $table->foreignId('bidder_id')->constrained('tender_bidders')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('tender_items')->cascadeOnDelete();
            $table->decimal('unit_price', 14, 2)->nullable();
            $table->timestamps();
            $table->unique(['bidder_id', 'item_id']);
        });

        Schema::create('tender_bidder_kpis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();
            $table->foreignId('bidder_id')->constrained('tender_bidders')->cascadeOnDelete();
            $table->foreignId('kpi_id')->constrained('tender_kpis')->cascadeOnDelete();
            $table->decimal('score', 6, 2)->nullable();
            $table->timestamps();
            $table->unique(['bidder_id', 'kpi_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_bidder_kpis');
        Schema::dropIfExists('tender_bidder_items');
        Schema::dropIfExists('tender_kpis');
        Schema::dropIfExists('tender_bidders');
        Schema::dropIfExists('tender_items');
        Schema::dropIfExists('tenders');
    }
};
