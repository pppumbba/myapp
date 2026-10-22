<?php

namespace Database\Seeders;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use Database\Seeders\Concerns\SeedsActions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A Tenders demo app, built around a workspace: one tender and everything about it.
 *
 *   Tenders (app)
 *   ├─ All tenders       -> datagrid; each row opens its workspace
 *   └─ Tender workspace  -> workspace on the tender form, with sections:
 *      ├─ Items           -> grid of the tender's items
 *      ├─ Bidders         -> grid of the tender's bidders
 *      ├─ KPIs            -> grid of the evaluation criteria and their weights
 *      ├─ Prices          -> matrix: each bidder's unit price for each item (lowest marked,
 *      │                     each bidder's total = price × quantity)
 *      └─ Evaluation      -> matrix: each bidder's score on each KPI (highest marked, each
 *                            bidder's score averaged by the KPIs' weights)
 *
 * Each section keeps to the open tender through json_config.scope ({"tender_id": "id"}).
 * Safe to run again: metas are matched by code and their params rebuilt; sample records are
 * only added to empty tables.
 */
class TenderDemoSeeder extends Seeder
{
    use SeedsActions;

    private const STATUSES = ['draft', 'open', 'evaluating', 'awarded', 'cancelled'];

    private const UNITS = ['pcs', 'box', 'set', 'license', 'month'];

    private array $types = [];

    public function run(): void
    {
        $this->call(MetaTypeSeeder::class);
        $this->types = MetaType::pluck('id', 'code')->all();

        DB::transaction(function () {
            $this->sampleData();
            $this->metas();
        });
    }

    private function metas(): void
    {
        $hidden = ['is_show' => false];
        $key = ['is_primary' => true, 'is_show' => false];

        $tenderForm = $this->meta('tender_form', 'Tender form', 'form', 'tenders', 'Announce a tender: what is bought, the budget and the deadline.');
        $this->params($tenderForm, [
            ['id', 'ID', 'integer', $key],
            ['tender_no', 'Tender number', 'string', ['is_required' => true, 'is_unique' => true, 'is_criteria' => true, 'json_config' => ['autonumber' => ['format' => 'TN-{yy}{MM}{seq:3}']]]],
            ['title', 'Title', 'string', ['is_required' => true, 'is_criteria' => true]],
            ['budget', 'Budget', 'decimal', ['min_value' => '0', 'render_type' => 'money']],
            ['deadline', 'Bids due', 'date'],
            ['status', 'Status', 'string', ['is_required' => true, 'default_value' => 'draft', 'render_type' => 'select', 'json_config' => ['options' => self::STATUSES]]],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);

        // Each section's grid has a hidden tender_id: the workspace keeps it to the open tender.
        $itemForm = $this->meta('tender_item_form', 'Tender item form', 'form', 'tender_items', 'One thing the tender buys.');
        $this->params($itemForm, [
            ['id', 'ID', 'integer', $key],
            ['tender_id', 'Tender', 'integer', $hidden],
            ['name', 'Item', 'string', ['is_required' => true]],
            ['quantity', 'Quantity', 'integer', ['is_required' => true, 'min_value' => '1', 'default_value' => '1']],
            ['unit', 'Unit', 'string', ['default_value' => 'pcs', 'render_type' => 'select', 'json_config' => ['options' => self::UNITS]]],
        ]);
        $itemGrid = $this->meta('tender_items_grid', 'Tender items', 'datagrid', 'tender_items', 'What the tender buys.');
        $this->actions($itemGrid, $itemForm);
        $this->params($itemGrid, [
            ['id', 'ID', 'integer', $key],
            ['tender_id', 'Tender', 'integer', $hidden],
            ['name', 'Item', 'string', ['is_criteria' => true]],
            ['quantity', 'Quantity', 'integer'],
            ['unit', 'Unit', 'string'],
        ]);

        $bidderForm = $this->meta('tender_bidder_form', 'Bidder form', 'form', 'tender_bidders', 'A company bidding on the tender.');
        $this->params($bidderForm, [
            ['id', 'ID', 'integer', $key],
            ['tender_id', 'Tender', 'integer', $hidden],
            ['name', 'Company', 'string', ['is_required' => true]],
            ['contact', 'Contact', 'string'],
        ]);
        $bidderGrid = $this->meta('tender_bidders_grid', 'Bidders', 'datagrid', 'tender_bidders', 'Who bid on the tender.');
        $this->actions($bidderGrid, $bidderForm);
        $this->params($bidderGrid, [
            ['id', 'ID', 'integer', $key],
            ['tender_id', 'Tender', 'integer', $hidden],
            ['name', 'Company', 'string', ['is_criteria' => true]],
            ['contact', 'Contact', 'string'],
        ]);

        $kpiForm = $this->meta('tender_kpi_form', 'KPI form', 'form', 'tender_kpis', 'A criterion bids are scored on, and how much it counts.');
        $this->params($kpiForm, [
            ['id', 'ID', 'integer', $key],
            ['tender_id', 'Tender', 'integer', $hidden],
            ['name', 'Criterion', 'string', ['is_required' => true]],
            ['weight', 'Weight', 'decimal', ['is_required' => true, 'min_value' => '0', 'default_value' => '1']],
        ]);
        $kpiGrid = $this->meta('tender_kpis_grid', 'KPIs', 'datagrid', 'tender_kpis', 'What bids are scored on.');
        $this->actions($kpiGrid, $kpiForm);
        $this->params($kpiGrid, [
            ['id', 'ID', 'integer', $key],
            ['tender_id', 'Tender', 'integer', $hidden],
            ['name', 'Criterion', 'string'],
            ['weight', 'Weight', 'decimal'],
        ]);

        $prices = $this->meta('tender_price_matrix', 'Prices', 'matrix', 'tender_bidder_items', 'Each bidder’s unit price for each item.', null, [
            'rows' => ['meta_id' => $itemGrid->id, 'label' => 'name'],
            'columns' => ['meta_id' => $bidderGrid->id, 'label' => 'name'],
            'cell' => ['row' => 'item_id', 'column' => 'bidder_id', 'value' => 'unit_price', 'type' => 'decimal'],
            'best' => 'min',
            'weight' => 'quantity',
            'total' => 'amount',
        ]);
        $scores = $this->meta('tender_score_matrix', 'Evaluation', 'matrix', 'tender_bidder_kpis', 'Each bidder’s score (0–10) on each KPI.', null, [
            'rows' => ['meta_id' => $kpiGrid->id, 'label' => 'name'],
            'columns' => ['meta_id' => $bidderGrid->id, 'label' => 'name'],
            'cell' => ['row' => 'kpi_id', 'column' => 'bidder_id', 'value' => 'score', 'type' => 'decimal'],
            'best' => 'max',
            'weight' => 'weight',
            'total' => 'weighted',
        ]);

        $workspace = $this->meta('tender_workspace', 'Tender workspace', 'workspace', 'tenders', 'One tender: its items, bidders, prices and evaluation.', $tenderForm, [
            'header' => ['tender_no', 'budget', 'deadline', 'status'],
            'title_field' => 'title',
        ]);
        $scope = ['scope' => ['tender_id' => 'id']];
        $this->params($workspace, [
            ['items', 'Items', 'string', ['ref_meta_id' => $itemGrid->id, 'icon_cls' => 'list', 'json_config' => $scope]],
            ['bidders', 'Bidders', 'string', ['ref_meta_id' => $bidderGrid->id, 'icon_cls' => 'users', 'json_config' => $scope]],
            ['kpis', 'KPIs', 'string', ['ref_meta_id' => $kpiGrid->id, 'icon_cls' => 'target', 'json_config' => $scope]],
            ['prices', 'Prices', 'string', ['ref_meta_id' => $prices->id, 'icon_cls' => 'grid', 'json_config' => $scope]],
            ['evaluation', 'Evaluation', 'string', ['ref_meta_id' => $scores->id, 'icon_cls' => 'chart', 'json_config' => $scope]],
        ]);

        $tenderGrid = $this->meta('tenders_grid', 'Tenders', 'datagrid', 'tenders', 'Every tender, from draft to awarded.', null, [
            'drilldown' => ['meta_id' => $workspace->id, 'params' => ['id' => 'id'], 'label' => 'Open workspace'],
        ]);
        $this->actions($tenderGrid, $tenderForm);
        $this->params($tenderGrid, [
            ['id', 'ID', 'integer', $key],
            ['tender_no', 'Number', 'string', ['is_criteria' => true]],
            ['title', 'Title', 'string', ['is_criteria' => true]],
            ['budget', 'Budget', 'decimal', ['render_type' => 'money']],
            ['deadline', 'Bids due', 'date'],
            ['status', 'Status', 'string', ['render_type' => 'status', 'is_criteria' => true, 'json_config' => ['options' => self::STATUSES]]],
        ]);

        $app = $this->meta('tender_app', 'Tenders', 'app', null, 'Tenders, their bidders, prices and evaluation.');
        $this->params($app, [
            ['all_tenders', 'All tenders', 'string', ['icon_cls' => 'file', 'ref_meta_id' => $tenderGrid->id]],
            ['workspace', 'Tender workspace', 'string', ['icon_cls' => 'briefcase', 'ref_meta_id' => $workspace->id]],
        ]);
    }

    private function meta(string $code, string $name, string $type, ?string $table, string $description, ?Meta $opens = null, ?array $config = null): Meta
    {
        return Meta::updateOrCreate(['code' => $code], [
            'name' => $name,
            'type_id' => $this->types[$type],
            'table_name' => $table,
            'description' => $description,
            'ref_meta_id' => $opens?->id,
            'json_config' => $config,
            'is_active' => true,
        ]);
    }

    /** Replaces a meta's params. Each row: [field_path, label, data_type, extra attributes]. */
    private function params(Meta $meta, array $rows): void
    {
        MetaParam::where('main_meta_id', $meta->id)->delete();

        foreach ($rows as $i => $row) {
            [$field, $label, $type] = $row;
            $extra = $row[3] ?? [];
            // The row's own attributes win, e.g. is_show false for a hidden tender_id.
            MetaParam::create($extra + [
                'main_meta_id' => $meta->id,
                'order_number' => $i + 1,
                'field_path' => $field,
                'param_name' => $field,
                'label_name' => $label,
                'data_type' => $type,
                'is_show' => true,
            ]);
        }
    }

    private function sampleData(): void
    {
        if (DB::table('tenders')->exists()) {
            return;
        }
        $now = now();
        $stamp = fn (array $row) => $row + ['created_at' => $now, 'updated_at' => $now];

        $tenders = [
            ['TN-2610001', 'Office laptops and monitors', 60000000, '2026-11-15', 'evaluating',
                [['Laptop 14"', 20, 'pcs'], ['27" Monitor', 20, 'pcs'], ['Docking station', 20, 'pcs']],
                ['Northwind Electronics', 'Steppe Office Supply', 'Nomin Trading LLC'],
                [['Price', 50], ['Delivery time', 20], ['Warranty', 20], ['Local support', 10]],
                [[2450000, 690000, 310000], [2380000, 720000, 295000], [2520000, 650000, 330000]],
                [[8, 7, 9, 6], [9, 6, 7, 8], [7, 9, 8, 9]]],
            ['TN-2610002', 'Cleaning services 2027', 18000000, '2026-12-01', 'open',
                [['Daily office cleaning', 12, 'month'], ['Window cleaning', 4, 'set']],
                ['Clean Steppe LLC', 'Sparkle Services'],
                [['Price', 60], ['Experience', 40]],
                [[1150000, 420000], [1090000, 450000]],
                [[7, 8], [8, 6]]],
        ];

        foreach ($tenders as [$no, $title, $budget, $deadline, $status, $items, $bidders, $kpis, $prices, $scores]) {
            $tender = DB::table('tenders')->insertGetId($stamp(['tender_no' => $no, 'title' => $title, 'budget' => $budget, 'deadline' => $deadline, 'status' => $status]));
            $itemIds = array_map(fn ($i) => DB::table('tender_items')->insertGetId($stamp(['tender_id' => $tender, 'name' => $i[0], 'quantity' => $i[1], 'unit' => $i[2]])), $items);
            $bidderIds = array_map(fn ($b) => DB::table('tender_bidders')->insertGetId($stamp(['tender_id' => $tender, 'name' => $b])), $bidders);
            $kpiIds = array_map(fn ($k) => DB::table('tender_kpis')->insertGetId($stamp(['tender_id' => $tender, 'name' => $k[0], 'weight' => $k[1]])), $kpis);

            foreach ($bidderIds as $b => $bidder) {
                foreach ($itemIds as $i => $item) {
                    DB::table('tender_bidder_items')->insert($stamp(['tender_id' => $tender, 'bidder_id' => $bidder, 'item_id' => $item, 'unit_price' => $prices[$b][$i]]));
                }
                foreach ($kpiIds as $k => $kpi) {
                    DB::table('tender_bidder_kpis')->insert($stamp(['tender_id' => $tender, 'bidder_id' => $bidder, 'kpi_id' => $kpi, 'score' => $scores[$b][$k]]));
                }
            }
        }
    }
}
