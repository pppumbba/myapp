<?php

namespace Database\Seeders;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use Illuminate\Database\Seeder;

/**
 * The Procurement app's statement and report, and the drilldowns between them:
 *
 *   Orders by supplier (statement)  orders grouped by supplier, subtotals and a grand total
 *   Purchase order (report)         one order printed: header fields, its lines, signatures
 *
 * A drilldown (json_config.drilldown = {meta_id, params: {target field: source}}) opens
 * another meta with values from what was clicked: a row's field, or for charts x, x.from,
 * x.to, range.from or range.to. Here the statement and the orders grid open the report.
 *
 * Needs the Procurement demo (ProcurementDemoSeeder calls this). Run on its own, it only
 * touches its own metas, the orders grid's drilldown and two menu items.
 */
class ProcurementReportsSeeder extends Seeder
{
    private array $types = [];

    public function run(): void
    {
        $this->call(MetaTypeSeeder::class);
        $this->types = MetaType::pluck('id', 'code')->all();

        $codes = ['proc_suppliers_grid', 'proc_order_line_form', 'proc_orders_grid', 'proc_order_form', 'proc_app'];
        $metas = Meta::whereIn('code', $codes)->get()->keyBy('code');
        if ($metas->count() < count($codes)) {
            $this->command?->warn('Run ProcurementDemoSeeder first: the statement and report read its tables.');

            return;
        }

        $report = $this->report($metas['proc_suppliers_grid'], $metas['proc_order_line_form']);
        $statement = $this->statement($report);

        $grid = $metas['proc_orders_grid'];
        $grid->update(['json_config' => [...($grid->json_config ?? []), 'drilldown' => ['meta_id' => $report->id, 'params' => ['id' => 'id'], 'label' => 'Print order']]]);

        // The order form gets Save and print: it saves the order, then prints it with the report.
        $form = $metas['proc_order_form'];
        $form->update(['json_config' => [...($form->json_config ?? []), 'print_meta_id' => $report->id]]);

        $app = $metas['proc_app'];
        foreach ([
            ['orders_by_supplier', 'Orders by supplier', 'file', $statement, 90],
            ['print_order', 'Print an order', 'receipt', $report, 91],
        ] as [$field, $label, $icon, $meta, $order]) {
            MetaParam::updateOrCreate(
                ['main_meta_id' => $app->id, 'field_path' => $field, 'parent_id' => null],
                ['param_name' => $field, 'label_name' => $label, 'data_type' => 'string', 'icon_cls' => $icon, 'ref_meta_id' => $meta->id, 'order_number' => $order, 'is_show' => true],
            );
        }
    }

    /** Orders grouped by supplier; the date range comes from the filters as named parameters. */
    private function statement(Meta $report): Meta
    {
        $statement = $this->meta('proc_orders_by_supplier', 'Orders by supplier', 'statement', 'proc_purchase_orders',
            'Every order, grouped by supplier, with what each one adds up to.', <<<'SQL'
                SELECT o.id, s.name AS supplier_name, o.po_number, o.order_date, o.expected_date, o.status, o.total_amount
                FROM proc_purchase_orders o
                JOIN proc_suppliers s ON s.id = o.supplier_id
                WHERE (:from_date IS NULL OR o.order_date >= :from_date)
                  AND (:to_date IS NULL OR o.order_date <= :to_date)
                SQL, [
                'group_by' => ['supplier_name'],
                'sort' => [['field' => 'order_date', 'dir' => 'asc']],
                'drilldown' => ['meta_id' => $report->id, 'params' => ['id' => 'id'], 'label' => 'Print order'],
            ]);

        $this->params($statement, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['supplier_name', 'Supplier', 'string'],
            ['po_number', 'PO number', 'string', ['json_config' => ['aggregate' => 'count']]],
            ['order_date', 'Ordered', 'date'],
            ['expected_date', 'Expected', 'date'],
            ['status', 'Status', 'string', ['render_type' => 'status']],
            ['total_amount', 'Total', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['from_date', 'Ordered from', 'date', ['is_show' => false, 'is_criteria' => true]],
            ['to_date', 'Ordered to', 'date', ['is_show' => false, 'is_criteria' => true]],
        ]);

        return $statement;
    }

    /**
     * One order on paper. Fields print in a grid at the top, a detail param prints its lines
     * as a table, json_config.total prints under the lines, and signature params leave a
     * line to sign.
     */
    private function report(Meta $supplierGrid, Meta $lineForm): Meta
    {
        $report = $this->meta('proc_order_report', 'Purchase order', 'report', 'proc_purchase_orders',
            'A purchase order to print or send to the supplier.', null, [
                'title' => 'Purchase order',
                'number_field' => 'po_number',
                'issuer' => 'Blue Sky LLC · Procurement',
                'note' => 'Please quote the PO number on every delivery note and invoice.',
            ]);

        $this->params($report, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['po_number', 'PO number', 'string'],
            ['supplier_id', 'Supplier', 'integer', ['is_select' => true, 'ref_meta_id' => $supplierGrid->id, 'display_field' => 'name']],
            ['order_date', 'Order date', 'date'],
            ['expected_date', 'Expected delivery', 'date'],
            ['notes', 'Notes', 'text'],
            ['lines', 'Items', 'detail', ['ref_meta_id' => $lineForm->id, 'json_config' => [
                'foreign_key' => 'purchase_order_id',
                'amount' => ['quantity', 'unit_price'],
            ]]],
            ['total_amount', 'Total', 'decimal', ['render_type' => 'money', 'json_config' => ['total' => true]]],
            ['ordered_by', 'Ordered by', 'signature'],
            ['approved_by', 'Approved by', 'signature'],
        ]);

        return $report;
    }

    private function meta(string $code, string $name, string $type, string $table, string $description, ?string $query, array $config): Meta
    {
        return Meta::updateOrCreate(['code' => $code], [
            'name' => $name,
            'type_id' => $this->types[$type],
            'table_name' => $table,
            'query_string' => $query,
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
