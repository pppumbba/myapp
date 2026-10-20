<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workflows. A workflow is a meta (type "workflow") whose table_name is the table its
 * records live in; each record keeps its current status in a status_id column, and every
 * move is written to wfm_status_log. A datagrid whose ref_meta_id is a workflow shows its
 * records' status and a "Change status" button.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wfm_status', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->foreignId('workflow_meta_id')->constrained('metas')->cascadeOnDelete();
            $table->string('color')->nullable();
            // A form opened for the record when it moves into this status; saving it completes the move.
            $table->foreignId('meta_id')->nullable()->constrained('metas')->nullOnDelete();
            $table->string('label_name')->nullable();
            $table->unique(['workflow_meta_id', 'code']);
        });

        Schema::create('wfm_transition', function (Blueprint $table) {
            $table->id();
            // No previous status: the way a record without a status gets its first one.
            $table->foreignId('prev_status_id')->nullable()->constrained('wfm_status')->cascadeOnDelete();
            $table->foreignId('next_status_id')->constrained('wfm_status')->cascadeOnDelete();
            // An expression on the record and the session, e.g. "totalAmount > 100000"; empty is always allowed.
            $table->text('criteria')->nullable();
            // Diagram links between transitions.
            $table->foreignId('prev_id')->nullable()->constrained('wfm_transition')->nullOnDelete();
            $table->foreignId('next_id')->nullable()->constrained('wfm_transition')->nullOnDelete();
            // The grid this transition is offered in; empty is everywhere the workflow is used.
            $table->foreignId('source_id')->nullable()->constrained('metas')->nullOnDelete();
            // False: drawn in the diagram, but nobody can use it.
            $table->boolean('is_transition')->default(true);
            $table->foreignId('workflow_meta_id')->constrained('metas')->cascadeOnDelete();
        });

        Schema::create('wfm_status_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_meta_id')->constrained('metas')->cascadeOnDelete();
            $table->unsignedBigInteger('record_id');
            // History keeps its statuses: a status that has been used can't be deleted.
            $table->foreignId('status_id')->constrained('wfm_status')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->foreignId('prev_status_id')->nullable()->constrained('wfm_status')->restrictOnDelete();
            // Seconds the record spent in the previous status.
            $table->unsignedBigInteger('time_spent')->nullable();
            $table->index(['workflow_meta_id', 'record_id']);
        });

        // The Procurement demo's purchase orders move through a workflow.
        if (Schema::hasTable('proc_purchase_orders')) {
            Schema::table('proc_purchase_orders', function (Blueprint $table) {
                $table->foreignId('status_id')->nullable()->constrained('wfm_status')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('proc_purchase_orders', 'status_id')) {
            Schema::table('proc_purchase_orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('status_id'));
        }
        Schema::dropIfExists('wfm_status_log');
        Schema::dropIfExists('wfm_transition');
        Schema::dropIfExists('wfm_status');
    }
};
