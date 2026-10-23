<?php

namespace Database\Seeders;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use Illuminate\Database\Seeder;

/**
 * A read-only grid of Veritech ERP purchase requests (view PL_MDVIEW_004), listed through its
 * runJson endpoint: one POST with the command and the date range, records under
 * response.result keyed "0", "1", ... (see ExternalService's service_list_body).
 *
 * The ERP session goes in .env as SERVICE_VERITECH_SESSION. The two dates are mandatory
 * filters: the view returns nothing without them.
 *
 * Safe to run again: the meta is matched by code and its params rebuilt.
 */
class VeritechPurchaseRequestsSeeder extends Seeder
{
    public function run(): void
    {
        $meta = Meta::updateOrCreate(['code' => 'veritech_purchase_requests'], [
            'name' => 'ERP purchase requests',
            'type_id' => MetaType::where('code', 'datagrid')->value('id'),
            'description' => 'Purchase requests read from Veritech ERP (PL_MDVIEW_004).',
            'is_active' => true,
            'service_url' => 'http://dev.veritech.mn:8080/erp-services/RestWS/runJson',
            'service_list_path' => 'response.result',
            'service_list_body' => json_encode(['request' => [
                'sessionId' => '{env:SERVICE_VERITECH_SESSION}',
                'command' => 'PL_MDVIEW_004',
                'parameters' => [
                    'systemmetagroupid' => '17441063050601',
                    'filterStartDate' => '{filter.filterStartDate}',
                    'filterEndDate' => '{filter.filterEndDate}',
                ],
            ]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ]);

        MetaParam::where('main_meta_id', $meta->id)->delete();
        foreach ([
            ['id', 'ID', 'string', ['is_primary' => true, 'is_show' => false]],
            ['booknumber', 'Number', 'string'],
            ['bookdate', 'Date', 'date'],
            ['typename', 'Type', 'string'],
            ['budgetdepartmentname', 'Department', 'string'],
            ['wfmstatusname', 'Status', 'string'],
            ['totalamount', 'Total', 'decimal', ['render_type' => 'money']],
            ['orderduedate', 'Due', 'date'],
            ['filterStartDate', 'From', 'date', ['is_show' => false, 'is_criteria' => true, 'is_mandatory_criteria' => true]],
            ['filterEndDate', 'To', 'date', ['is_show' => false, 'is_criteria' => true, 'is_mandatory_criteria' => true]],
        ] as $i => $row) {
            [$field, $label, $type] = $row;
            MetaParam::create([
                'main_meta_id' => $meta->id,
                'order_number' => $i + 1,
                'field_path' => $field,
                'param_name' => $field,
                'label_name' => $label,
                'data_type' => $type,
            ] + ($row[3] ?? []));
        }
    }
}
