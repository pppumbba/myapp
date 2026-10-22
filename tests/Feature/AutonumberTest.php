<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\User;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AutonumberTest extends TestCase
{
    use RefreshDatabase;

    private int $form;

    private MetaParam $number;

    private int $itemId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->travelTo('2026-10-21 09:00:00');

        $this->form = Meta::where('code', 'proc_request_form')->value('id');
        $this->itemId = DB::table('proc_items')->value('id');
        $this->number = MetaParam::where('main_meta_id', $this->form)->where('field_path', 'request_no')->first();
        $this->number->update(['json_config' => ['autonumber' => [
            'format' => 'PR-{yy}{MM}{dd}{seq:3}',
            'rules' => [['field' => 'department', 'value' => 'IT', 'format' => 'ITR-{yy}{MM}{dd}{seq:3}']],
        ]]]);
    }

    private function save(array $values = []): string
    {
        return $this->postJson("/api/metas/{$this->form}/data", $values + ['title' => 'Chairs', 'item_id' => $this->itemId, 'quantity' => 1, 'status' => 'draft'])
            ->assertCreated()
            ->json('request_no');
    }

    public function test_each_new_record_takes_the_next_number_and_a_typed_one_is_ignored(): void
    {
        $this->assertSame('PR-261021001', $this->save());
        $this->assertSame('PR-261021002', $this->save(['request_no' => 'MINE-1']));
    }

    public function test_a_rule_picks_another_format_with_its_own_count(): void
    {
        $this->save();
        $this->assertSame('ITR-261021001', $this->save(['department' => 'IT']));
        $this->assertSame('PR-261021002', $this->save(['department' => 'HR']));
    }

    public function test_the_count_starts_again_when_the_date_in_it_changes(): void
    {
        $this->save();
        $this->travelTo('2026-10-22 08:00:00');

        $this->assertSame('PR-261022001', $this->save());
    }

    public function test_numbers_already_in_the_table_are_skipped(): void
    {
        DB::table('proc_purchase_requests')->insert(['request_no' => 'PR-261021007', 'title' => 'Typed by hand', 'quantity' => 1, 'status' => 'draft']);

        $this->assertSame('PR-261021008', $this->save());
    }

    public function test_the_preview_says_the_next_number_without_taking_it(): void
    {
        $this->getJson("/api/metas/{$this->form}/autonumber?values[department]=IT")->assertOk()->assertExactJson(['request_no' => 'ITR-261021001']);
        $this->getJson("/api/metas/{$this->form}/autonumber")->assertExactJson(['request_no' => 'PR-261021001']);

        $this->assertSame('PR-261021001', $this->save());
    }

    public function test_an_editable_autonumber_keeps_a_typed_value_and_numbers_a_blank_one(): void
    {
        $this->number->update(['json_config' => ['autonumber' => ['format' => 'PR-{seq:4}', 'editable' => true]]]);

        $this->assertSame('MINE-1', $this->save(['request_no' => 'MINE-1']));
        $this->assertSame('PR-0001', $this->save(['request_no' => '']));
    }

    public function test_the_purchase_order_form_numbers_its_orders(): void
    {
        $form = Meta::where('code', 'proc_order_form')->value('id');
        $order = ['supplier_id' => DB::table('proc_suppliers')->value('id'), 'order_date' => '2026-10-21', 'status' => 'draft'];

        $this->postJson("/api/metas/{$form}/data", $order)->assertCreated()->assertJsonPath('po_number', 'PO-261021001');
        $this->postJson("/api/metas/{$form}/data", $order + ['po_number' => 'MINE'])->assertCreated()->assertJsonPath('po_number', 'PO-261021002');
    }

    public function test_a_format_needs_exactly_one_seq(): void
    {
        $this->putJson("/api/params/{$this->number->id}", ['json_config' => ['autonumber' => ['format' => 'PR-{yy}']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('json_config');
    }
}
