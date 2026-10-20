<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Connects a datagrid to the forms its toolbar buttons open. Each row is one button:
 * what it does (add, edit, delete, custom), which form it opens, and which "get" meta
 * decides the values that form opens with.
 *
 * Grids used to open their form through metas.ref_meta_id; those links become
 * add/edit/delete buttons here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grid_meta_id')->constrained('metas')->cascadeOnDelete();
            $table->string('action', 20);
            $table->string('label');
            $table->string('icon_cls')->nullable();
            $table->foreignId('form_meta_id')->nullable()->constrained('metas')->nullOnDelete();
            $table->foreignId('get_meta_id')->nullable()->constrained('metas')->nullOnDelete();
            // Needs a selected row (edit, delete); also shown as an icon on each row.
            $table->boolean('needs_row')->default(false);
            $table->boolean('show_in_row')->default(false);
            $table->integer('order_number')->default(1);
            // params: the get meta's named parameters <- fields of the selected row.
            $table->json('json_config')->nullable();
            $table->timestamps();
            $table->index(['grid_meta_id', 'order_number']);
        });

        $gridType = DB::table('meta_types')->where('code', 'datagrid')->value('id');
        if (! $gridType) {
            return;
        }

        $now = now();
        $grids = DB::table('metas')->where('type_id', $gridType)->whereNotNull('ref_meta_id')->get(['id', 'ref_meta_id']);
        foreach ($grids as $grid) {
            DB::table('meta_actions')->insert([
                ['grid_meta_id' => $grid->id, 'action' => 'add', 'label' => 'Add', 'icon_cls' => 'plus', 'form_meta_id' => $grid->ref_meta_id, 'needs_row' => false, 'show_in_row' => false, 'order_number' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['grid_meta_id' => $grid->id, 'action' => 'edit', 'label' => 'Edit', 'icon_cls' => 'edit', 'form_meta_id' => $grid->ref_meta_id, 'needs_row' => true, 'show_in_row' => true, 'order_number' => 2, 'created_at' => $now, 'updated_at' => $now],
                ['grid_meta_id' => $grid->id, 'action' => 'delete', 'label' => 'Delete', 'icon_cls' => 'trash', 'form_meta_id' => $grid->ref_meta_id, 'needs_row' => true, 'show_in_row' => true, 'order_number' => 3, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
        DB::table('metas')->whereIn('id', $grids->pluck('id'))->update(['ref_meta_id' => null]);
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_actions');
    }
};
