<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaMetaMap;
use App\Models\MetaNotificationMap;
use App\Models\MetaParam;
use App\Models\User;
use Database\Seeders\ProcurementDemoSeeder;
use Database\Seeders\ProcurementNotificationsSeeder;
use Database\Seeders\ProcurementReportsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Links between metas live in meta_meta_map and meta_notification_map, not in json_config. */
class MetaMapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ProcurementDemoSeeder::class, ProcurementReportsSeeder::class, ProcurementNotificationsSeeder::class]);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function meta(string $code): Meta
    {
        return Meta::where('code', $code)->firstOrFail();
    }

    private function storedConfig(string $table, int $id): ?array
    {
        return json_decode(DB::table($table)->where('id', $id)->value('json_config') ?? 'null', true);
    }

    public function test_a_drilldown_is_a_map_row_and_reads_back_inside_json_config(): void
    {
        $grid = $this->meta('proc_orders_grid');
        $report = $this->meta('proc_order_report');

        $link = MetaMetaMap::where(['src_meta_id' => $grid->id, 'type' => 'drilldown'])->sole();
        $this->assertSame([$report->id, 'id', 'id', null], [$link->trg_meta_id, $link->src_path, $link->trg_path, $link->src_param_id]);
        // Only what isn't a link stays in the column.
        $this->assertSame(['label' => 'Print order'], $this->storedConfig('metas', $grid->id)['drilldown']);

        $this->getJson("/api/metas/{$grid->id}")->assertOk()
            ->assertJsonPath('json_config.drilldown', ['meta_id' => $report->id, 'params' => ['id' => 'id'], 'label' => 'Print order']);
    }

    public function test_deleting_the_target_removes_the_link_instead_of_leaving_a_dead_id(): void
    {
        $grid = $this->meta('proc_orders_grid');
        $this->deleteJson('/api/metas/'.$this->meta('proc_order_report')->id)->assertNoContent();

        $this->assertSame(0, MetaMetaMap::where(['src_meta_id' => $grid->id, 'type' => 'drilldown'])->count());
        $this->getJson("/api/metas/{$grid->id}")->assertOk()->assertJsonPath('json_config.drilldown', ['label' => 'Print order']);
    }

    public function test_a_detail_connects_header_and_line_forms_through_the_map(): void
    {
        $order = $this->meta('proc_order_form');
        $lines = MetaParam::where(['main_meta_id' => $order->id, 'field_path' => 'lines'])->first();

        $link = MetaMetaMap::where(['src_param_id' => $lines->id, 'type' => 'detail'])->sole();
        $this->assertSame([$order->id, $this->meta('proc_order_line_form')->id, 'id', 'purchase_order_id'], [$link->src_meta_id, $link->trg_meta_id, $link->src_path, $link->trg_path]);
        $this->assertArrayNotHasKey('foreign_key', $this->storedConfig('meta_params', $lines->id));
        $this->assertSame('purchase_order_id', $lines->fresh()->json_config['foreign_key']);
    }

    public function test_saving_json_config_through_the_api_moves_links_out_of_it(): void
    {
        $grid = $this->meta('proc_requests_grid');
        $form = $this->meta('proc_request_form');

        $this->putJson("/api/metas/{$grid->id}", ['json_config' => ['drilldown' => ['meta_id' => $form->id, 'params' => ['id' => 'id', 'status' => '=draft']]]])->assertOk()
            ->assertJsonPath('json_config.drilldown.params', ['id' => 'id', 'status' => '=draft']);

        $this->assertNull($this->storedConfig('metas', $grid->id));
        $this->assertSame(['id' => 'id', 'status' => '=draft'], MetaMetaMap::where(['src_meta_id' => $grid->id, 'type' => 'drilldown'])->pluck('src_path', 'trg_path')->all());

        // Saving the config without it removes the link.
        $this->putJson("/api/metas/{$grid->id}", ['json_config' => ['note' => 'none']])->assertOk();
        $this->assertSame(0, MetaMetaMap::where(['src_meta_id' => $grid->id])->count());
    }

    public function test_a_notification_watches_its_source_through_the_notification_map(): void
    {
        $rule = $this->meta('proc_notify_new_request');
        $form = $this->meta('proc_request_form');

        $this->assertSame($form->id, MetaNotificationMap::where('notification_id', $rule->id)->value('meta_id'));
        $this->assertArrayNotHasKey('source_meta_id', $this->storedConfig('metas', $rule->id));
        $this->assertSame($form->id, $rule->json_config['source_meta_id']);
        $this->assertSame($this->meta('proc_requests_grid')->id, $rule->json_config['link_meta_id']);
    }

    public function test_an_old_label_field_becomes_display_field_and_a_lookup_can_store_another_column(): void
    {
        $form = $this->meta('proc_request_form');
        $item = MetaParam::where(['main_meta_id' => $form->id, 'field_path' => 'item_id'])->first();
        $this->putJson("/api/params/{$item->id}", ['json_config' => ['label_field' => 'code']])->assertOk()->assertJsonPath('display_field', 'code');
        $this->assertArrayNotHasKey('label_field', $item->fresh()->json_config ?? []);

        // notes looks up items by their code: a code that exists is valid, any other is not.
        MetaParam::where(['main_meta_id' => $form->id, 'field_path' => 'notes'])->first()
            ->update(['is_select' => true, 'ref_meta_id' => $item->ref_meta_id, 'value_field' => 'code']);
        $values = ['request_no' => 'PR-V-1', 'title' => 'Chairs', 'item_id' => DB::table('proc_items')->value('id'), 'quantity' => 1, 'status' => 'draft'];

        $this->postJson("/api/metas/{$form->id}/data", $values + ['notes' => 'IT-002'])->assertCreated();
        $this->postJson("/api/metas/{$form->id}/data", ['request_no' => 'PR-V-2'] + $values + ['notes' => 'NOPE'])->assertJsonValidationErrors('notes');
    }

    public function test_a_lookup_shows_its_meta_label_field_when_it_names_none(): void
    {
        $param = new MetaParam(['ref_meta_id' => $this->meta('proc_items_grid')->id]);
        $this->assertSame('name', $param->displayField());

        MetaParam::where(['main_meta_id' => $this->meta('proc_items_grid')->id, 'field_path' => 'code'])->update(['is_label' => true]);
        $this->assertSame('code', $param->displayField());
    }

    public function test_a_forms_print_report_is_a_print_link(): void
    {
        $form = $this->meta('proc_order_form');
        $report = $this->meta('proc_order_report');

        $this->getJson("/api/metas/{$form->id}")->assertJsonPath('json_config.print_meta_id', $report->id);
        $this->assertSame($report->id, MetaMetaMap::where(['src_meta_id' => $form->id, 'type' => 'print'])->value('trg_meta_id'));

        $this->putJson("/api/metas/{$form->id}", ['json_config' => ['print_meta_id' => null, 'save_and_add' => false]])->assertOk()
            ->assertJsonPath('json_config', ['save_and_add' => false]);
        $this->assertSame(0, MetaMetaMap::where(['src_meta_id' => $form->id, 'type' => 'print'])->count());
    }

    public function test_a_version_restores_links_too(): void
    {
        $grid = $this->meta('proc_orders_grid');
        $version = $this->postJson("/api/metas/{$grid->id}/versions")->assertCreated()->json('id');
        $this->putJson("/api/metas/{$grid->id}", ['json_config' => []])->assertOk();
        $this->assertSame(0, MetaMetaMap::where('src_meta_id', $grid->id)->count());

        $this->postJson("/api/meta-versions/{$version}/restore")->assertOk();
        $this->getJson("/api/metas/{$grid->id}")->assertJsonPath('json_config.drilldown.meta_id', $this->meta('proc_order_report')->id);
    }
}
