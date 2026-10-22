<?php

namespace Database\Seeders;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The Procurement app's Overview: a dashboard meta whose params place chart metas on a
 * 12-column grid (json_config.w columns wide, h rows tall). Each chart's json_config says
 * what it counts (see App\Support\ChartQuery).
 *
 * Needs the Procurement demo (ProcurementDemoSeeder calls this). Run on its own, it only
 * touches its own metas and adds "Overview" to the app's menu, so it never undoes edits
 * made to the rest of the demo.
 */
class ProcurementDashboardSeeder extends Seeder
{
    private array $types = [];

    public function run(): void
    {
        $this->call(MetaTypeSeeder::class);
        $this->types = MetaType::pluck('id', 'code')->all();

        $supplierGrid = Meta::where('code', 'proc_suppliers_grid')->first();
        $itemGrid = Meta::where('code', 'proc_items_grid')->first();
        $app = Meta::where('code', 'proc_app')->first();
        if (! $supplierGrid || ! $itemGrid || ! $app) {
            $this->command?->warn('Run ProcurementDemoSeeder first: the Overview charts read its tables.');

            return;
        }

        $dashboard = $this->dashboard($supplierGrid, $itemGrid);

        // First in the menu: the app's own items are numbered from 1.
        MetaParam::updateOrCreate(
            ['main_meta_id' => $app->id, 'field_path' => 'overview', 'parent_id' => null],
            ['param_name' => 'overview', 'label_name' => 'Overview', 'data_type' => 'string', 'icon_cls' => 'chart', 'ref_meta_id' => $dashboard->id, 'order_number' => 0, 'is_show' => true],
        );
    }

    private function dashboard(Meta $supplierGrid, Meta $itemGrid): Meta
    {
        $spend = ['agg' => 'sum', 'column' => 'total_amount', 'label' => 'Spend'];

        // Clicking a chart opens the records behind it (see ProcurementReportsSeeder).
        $drill = function (string $code, array $params): array {
            $id = Meta::where('code', $code)->value('id');

            return $id ? ['drilldown' => ['meta_id' => $id, 'params' => $params]] : [];
        };
        $ordersInRange = $drill('proc_orders_by_supplier', ['from_date' => 'range.from', 'to_date' => 'range.to']);

        $charts = [
            [$this->chart('proc_chart_spend', 'Total spend', 'proc_purchase_orders', 'Everything ordered, in the period.',
                ['type' => 'kpi', 'measures' => [$spend], 'date_field' => 'order_date', 'format' => 'money'] + $ordersInRange), 3, 1],
            [$this->chart('proc_chart_orders', 'Purchase orders', 'proc_purchase_orders', 'Orders placed in the period.',
                ['type' => 'kpi', 'measures' => [['agg' => 'count', 'label' => 'Orders']], 'date_field' => 'order_date'] + $ordersInRange), 3, 1],
            [$this->chart('proc_chart_order_value', 'Average order', 'proc_purchase_orders', 'The typical order size.',
                ['type' => 'kpi', 'measures' => [['agg' => 'avg', 'column' => 'total_amount', 'label' => 'Average order']], 'date_field' => 'order_date', 'format' => 'money']), 3, 1],
            [$this->chart('proc_chart_requests', 'Requests to approve', 'proc_purchase_requests', 'Submitted and waiting for approval.',
                ['type' => 'kpi', 'measures' => [['agg' => 'count', 'label' => 'Requests']], 'filters' => [['column' => 'status', 'op' => '=', 'value' => 'submitted']]]), 3, 1],
            [$this->chart('proc_chart_spend_by_week', 'Spend by week', 'proc_purchase_orders', 'Order totals, week by week.',
                ['type' => 'column', 'x' => 'order_date', 'bucket' => 'week', 'measures' => [$spend], 'date_field' => 'order_date', 'format' => 'money']
                    + $drill('proc_orders_by_supplier', ['from_date' => 'x.from', 'to_date' => 'x.to'])), 8, 2],
            [$this->chart('proc_chart_requests_by_status', 'Requests by status', 'proc_purchase_requests', 'Where purchase requests stand.',
                ['type' => 'donut', 'x' => 'status', 'measures' => [['agg' => 'count', 'label' => 'Requests']]]
                    + $drill('proc_requests_grid', ['status' => 'x'])), 4, 2],
            [$this->chart('proc_chart_spend_by_supplier', 'Spend by supplier', 'proc_purchase_orders', 'Who we buy the most from.',
                ['type' => 'bar', 'x' => 'supplier_id', 'x_lookup' => ['meta_id' => $supplierGrid->id, 'label_field' => 'name'], 'measures' => [$spend], 'date_field' => 'order_date', 'format' => 'money']
                    + $drill('proc_supplier_orders_grid', ['supplier_id' => 'x'])), 6, 2],
            [$this->chart('proc_chart_requests_by_department', 'Requests by department', 'proc_purchase_requests', 'Requested quantity, by department.',
                ['type' => 'column', 'x' => 'department', 'measures' => [['agg' => 'sum', 'column' => 'quantity', 'label' => 'Quantity']]]), 6, 2],
            [$this->chart('proc_chart_lines_by_item', 'Ordered items', 'proc_purchase_order_lines', 'Quantity and order lines per item.',
                ['type' => 'table', 'x' => 'item_id', 'x_lookup' => ['meta_id' => $itemGrid->id, 'label_field' => 'name'], 'measures' => [
                    ['agg' => 'sum', 'column' => 'quantity', 'label' => 'Quantity'],
                    ['agg' => 'count', 'label' => 'Order lines'],
                ]]), 12, 2],
        ];

        $dashboard = Meta::updateOrCreate(['code' => 'proc_overview'], [
            'name' => 'Procurement overview',
            'type_id' => $this->types['dashboard'],
            'description' => 'Spend, orders and requests at a glance.',
            'is_active' => true,
        ]);

        MetaParam::where('main_meta_id', $dashboard->id)->delete();
        foreach ($charts as $i => [$chart, $w, $h]) {
            $field = Str::after($chart->code, 'proc_chart_');
            MetaParam::create([
                'main_meta_id' => $dashboard->id,
                'order_number' => $i + 1,
                'field_path' => $field,
                'param_name' => $field,
                'data_type' => 'chart',
                'ref_meta_id' => $chart->id,
                'json_config' => ['w' => $w, 'h' => $h],
            ]);
        }

        return $dashboard;
    }

    private function chart(string $code, string $name, string $table, string $description, array $config): Meta
    {
        return Meta::updateOrCreate(['code' => $code], [
            'name' => $name,
            'type_id' => $this->types['chart'],
            'table_name' => $table,
            'description' => $description,
            'json_config' => $config,
            'is_active' => true,
        ]);
    }
}
