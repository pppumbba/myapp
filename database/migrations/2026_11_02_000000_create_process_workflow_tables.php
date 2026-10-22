<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Process flows: when a form (the main process) saves a new record, the forms connected to
 * it save theirs too, in the same transaction.
 *
 * - meta_process_workflow: one step, a form (process_id) run after the main form.
 * - meta_process_transition: which step runs after which. No source means right after the
 *   main form; criteria ([{field, op, value}]) must hold for the record just saved. A step
 *   no transition leads to starts right after the main form.
 * - meta_process_behaviour: where a step's values come from. source_path is a field of the
 *   record just saved, "main.field" of the main form's record, or "=text" a fixed value;
 *   target_path is the step form's field it goes into.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_process_workflow', function (Blueprint $table) {
            $table->id();
            $table->foreignId('main_process_id')->constrained('metas')->cascadeOnDelete();
            $table->foreignId('process_id')->constrained('metas')->cascadeOnDelete();
            $table->integer('order_number')->default(0);
            $table->timestamps();
        });

        Schema::create('meta_process_transition', function (Blueprint $table) {
            $table->id();
            $table->foreignId('main_process_id')->constrained('metas')->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained('meta_process_workflow')->cascadeOnDelete();
            $table->foreignId('target_id')->constrained('meta_process_workflow')->cascadeOnDelete();
            $table->json('criteria')->nullable();
            $table->timestamps();
        });

        Schema::create('meta_process_behaviour', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('meta_process_workflow')->cascadeOnDelete();
            $table->string('source_path');
            $table->string('target_path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_process_behaviour');
        Schema::dropIfExists('meta_process_transition');
        Schema::dropIfExists('meta_process_workflow');
    }
};
