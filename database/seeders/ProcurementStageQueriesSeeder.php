<?php

namespace Database\Seeders;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use Illuminate\Database\Seeder;

/**
 * The Procurement app's stage query (see StageQuery):
 *
 *   Close overdue drafts   rejects draft requests needed before a date (today when none is given)
 *
 * It has a menu item, so it can be run by hand; a form's process flow or a schedule can run
 * it too. Needs the Procurement demo (ProcurementDemoSeeder calls this).
 */
class ProcurementStageQueriesSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(MetaTypeSeeder::class);
        $app = Meta::where('code', 'proc_app')->first();
        if (! $app) {
            $this->command?->warn('Run ProcurementDemoSeeder first: the stage query changes its requests.');

            return;
        }

        $query = Meta::updateOrCreate(['code' => 'proc_close_overdue_drafts'], [
            'name' => 'Close overdue drafts',
            'type_id' => MetaType::where('code', 'stage_query')->value('id'),
            'table_name' => 'proc_purchase_requests',
            'query_string' => "UPDATE proc_purchase_requests\nSET status = 'rejected', updated_at = CURRENT_TIMESTAMP\nWHERE status = 'draft' AND needed_by < COALESCE(:before, :today)",
            'description' => 'Rejects the draft requests that were needed before a date and never sent for approval.',
            'is_active' => true,
        ]);
        MetaParam::where('main_meta_id', $query->id)->delete();
        MetaParam::create([
            'main_meta_id' => $query->id,
            'order_number' => 1,
            'field_path' => 'before',
            'param_name' => 'before',
            'label_name' => 'Needed before',
            'data_type' => 'date',
            'is_show' => true,
        ]);

        MetaParam::updateOrCreate(
            ['main_meta_id' => $app->id, 'field_path' => 'close_overdue_drafts', 'parent_id' => null],
            ['param_name' => 'close_overdue_drafts', 'label_name' => 'Close overdue drafts', 'data_type' => 'string', 'icon_cls' => 'zap', 'ref_meta_id' => $query->id, 'order_number' => 95, 'is_show' => true],
        );
    }
}
