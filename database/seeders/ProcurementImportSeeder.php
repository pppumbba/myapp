<?php

namespace Database\Seeders;

use App\Models\Meta;
use App\Models\MetaAction;
use App\Models\MetaParam;
use App\Models\MetaType;
use Illuminate\Database\Seeder;

/**
 * The Procurement app's Excel imports, each an excel_import meta whose params map the
 * file's columns (see ExcelImport):
 *
 *   Import items      upserts the catalog by item code: new codes are added, known ones updated
 *   Import requests   adds purchase requests; the Item column holds item names, saved as ids
 *
 * Each also gets an "Import" button on its grid (a meta_action of kind import). Every grid
 * can export to Excel without any setup.
 *
 * Needs the Procurement demo (ProcurementDemoSeeder calls this). Run on its own, it only
 * touches its own metas and the import buttons.
 */
class ProcurementImportSeeder extends Seeder
{
    private const UNITS = ['pcs', 'box', 'kg', 'l', 'm', 'pack'];

    private const DEPARTMENTS = ['Operations', 'Finance', 'IT', 'Sales', 'HR'];

    private const REQUEST_STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'ordered'];

    private array $types = [];

    public function run(): void
    {
        $this->call(MetaTypeSeeder::class);
        $this->types = MetaType::pluck('id', 'code')->all();

        $codes = ['proc_items_grid', 'proc_requests_grid'];
        $grids = Meta::whereIn('code', $codes)->get()->keyBy('code');
        if ($grids->count() < count($codes)) {
            $this->command?->warn('Run ProcurementDemoSeeder first: the imports fill its tables.');

            return;
        }

        $items = $this->meta('proc_items_import', 'Import items', 'proc_items', 'Adds new items and updates known ones, matched by item code.', ['mode' => 'upsert', 'key' => ['code']]);
        $this->params($items, [
            ['code', 'Item code', 'string', ['is_required' => true, 'is_unique' => true]],
            ['name', 'Name', 'string', ['is_required' => true]],
            ['category', 'Category', 'string'],
            ['unit', 'Unit', 'string', ['default_value' => 'pcs', 'json_config' => ['options' => self::UNITS]]],
            ['unit_price', 'Unit price', 'decimal', ['is_required' => true, 'min_value' => '0']],
        ]);

        $requests = $this->meta('proc_requests_import', 'Import purchase requests', 'proc_purchase_requests', 'Adds purchase requests; write the item by its name.', ['mode' => 'insert']);
        $this->params($requests, [
            ['request_no', 'Request number', 'string', ['is_required' => true, 'is_unique' => true]],
            ['title', 'Title', 'string', ['is_required' => true]],
            ['requested_by', 'Requested by', 'string'],
            ['department', 'Department', 'string', ['json_config' => ['options' => self::DEPARTMENTS]]],
            ['item_id', 'Item', 'integer', ['is_select' => true, 'is_required' => true, 'ref_meta_id' => $grids['proc_items_grid']->id, 'display_field' => 'name']],
            ['quantity', 'Quantity', 'integer', ['is_required' => true, 'min_value' => '1', 'default_value' => '1']],
            ['needed_by', 'Needed by', 'date'],
            ['status', 'Status', 'string', ['is_required' => true, 'default_value' => 'draft', 'json_config' => ['options' => self::REQUEST_STATUSES]]],
            ['notes', 'Notes', 'text'],
        ]);

        foreach ([[$grids['proc_items_grid'], $items], [$grids['proc_requests_grid'], $requests]] as [$grid, $import]) {
            MetaAction::updateOrCreate(
                ['grid_meta_id' => $grid->id, 'action' => 'import'],
                ['label' => 'Import', 'icon_cls' => 'upload', 'form_meta_id' => $import->id, 'needs_row' => false, 'show_in_row' => false, 'order_number' => 10],
            );
        }
    }

    private function meta(string $code, string $name, string $table, string $description, array $config): Meta
    {
        return Meta::updateOrCreate(['code' => $code], [
            'name' => $name,
            'type_id' => $this->types['excel_import'],
            'table_name' => $table,
            'description' => $description,
            'json_config' => $config,
            'is_active' => true,
        ]);
    }

    private function params(Meta $meta, array $rows): void
    {
        MetaParam::where('main_meta_id', $meta->id)->delete();
        foreach ($rows as $i => [$field, $label, $type]) {
            MetaParam::create([
                'main_meta_id' => $meta->id,
                'order_number' => $i + 1,
                'field_path' => $field,
                'param_name' => $field,
                'label_name' => $label,
                'data_type' => $type,
            ] + ($rows[$i][3] ?? []));
        }
    }
}
