<?php

namespace Database\Seeders;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use App\Models\WfmStatus;
use Illuminate\Database\Seeder;

/**
 * The Procurement app's notifications, each a notification meta (see Notifier):
 *
 *   New purchase request   a request is added       -> administrators
 *   Request approved       a request's status turns approved -> whoever asked (requested_by)
 *   Order sent             an order moves to Sent in its workflow -> administrators
 *
 * The meta's json_config is when it goes and what it says; its params are who gets it.
 *
 * Needs the Procurement demo (ProcurementDemoSeeder calls this). Run on its own, it only
 * touches its own metas.
 */
class ProcurementNotificationsSeeder extends Seeder
{
    private array $types = [];

    public function run(): void
    {
        $this->call(MetaTypeSeeder::class);
        $this->types = MetaType::pluck('id', 'code')->all();

        $codes = ['proc_request_form', 'proc_requests_grid', 'proc_orders_grid', 'proc_order_workflow', 'proc_order_report'];
        $m = Meta::whereIn('code', $codes)->pluck('id', 'code');
        if ($m->count() < count($codes)) {
            $this->command?->warn('Run ProcurementDemoSeeder first: the notifications watch its records.');

            return;
        }

        $this->notification('proc_notify_new_request', 'New purchase request', 'Tells administrators when someone asks for something to be bought.', [
            'event' => 'created',
            'source_meta_id' => $m['proc_request_form'],
            'kind' => 'info',
            'title' => 'New request {request_no}: {title}',
            'body' => '{actor} asked for {quantity} × {item_id} for {department}, needed by {needed_by}.',
            'link_meta_id' => $m['proc_requests_grid'],
            'link_record' => false,
        ], [['role', 'admin']]);

        $this->notification('proc_notify_request_approved', 'Request approved', 'Tells whoever asked when their request is approved.', [
            'event' => 'updated',
            'source_meta_id' => $m['proc_request_form'],
            'changed_field' => 'status',
            'conditions' => [['field' => 'status', 'op' => '=', 'value' => 'approved']],
            'kind' => 'success',
            'title' => 'Your request {request_no} was approved',
            'body' => '{actor} approved "{title}" (it was {old.status}). It will be ordered next.',
            'link_meta_id' => $m['proc_requests_grid'],
        ], [['field', 'requested_by']]);

        $this->notification('proc_notify_order_sent', 'Order sent', 'Tells administrators when an order goes to its supplier.', [
            'event' => 'status_changed',
            'workflow_meta_id' => $m['proc_order_workflow'],
            'status_id' => WfmStatus::where('workflow_meta_id', $m['proc_order_workflow'])->where('code', 'sent')->value('id'),
            'source_meta_id' => $m['proc_orders_grid'],
            'kind' => 'info',
            'title' => 'Order {po_number} is {status}',
            'body' => '{actor} sent it to the supplier. Total: {total_amount}.',
            'link_meta_id' => $m['proc_order_report'],
            'link_record' => true,
        ], [['role', 'admin']]);
    }

    /** @param  list<array{0: string, 1: string}>  $recipients  [kind, value] */
    private function notification(string $code, string $name, string $description, array $config, array $recipients): void
    {
        $meta = Meta::updateOrCreate(['code' => $code], [
            'name' => $name,
            'type_id' => $this->types['notification'],
            'description' => $description,
            'json_config' => $config + ['exclude_actor' => true],
            'is_active' => true,
        ]);

        MetaParam::where('main_meta_id', $meta->id)->delete();
        foreach ($recipients as $i => [$kind, $value]) {
            MetaParam::create([
                'main_meta_id' => $meta->id,
                'order_number' => $i + 1,
                'field_path' => $value,
                'param_name' => $value,
                'data_type' => $kind,
                'is_show' => true,
            ]);
        }
    }
}
