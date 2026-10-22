<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\User;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProcessFlowTest extends TestCase
{
    use RefreshDatabase;

    private int $requestForm;

    private int $orderForm;

    private int $itemForm;

    private int $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->travelTo('2026-10-21 09:00:00');

        $this->requestForm = Meta::where('code', 'proc_request_form')->value('id');
        $this->orderForm = Meta::where('code', 'proc_order_form')->value('id');
        $this->itemForm = Meta::where('code', 'proc_item_form')->value('id');
        $this->supplier = DB::table('proc_suppliers')->value('id');
    }

    /** A request makes an order; an order whose notes mention chairs also makes an item. */
    private function saveFlow(array $orderCriteria = []): void
    {
        $this->putJson("/api/metas/{$this->requestForm}/process-flow", [
            'steps' => [
                ['key' => 'order', 'process_id' => $this->orderForm, 'behaviours' => [
                    ['source_path' => "={$this->supplier}", 'target_path' => 'supplier_id'],
                    ['source_path' => 'needed_by', 'target_path' => 'order_date'],
                    ['source_path' => 'title', 'target_path' => 'notes'],
                ]],
                ['key' => 'item', 'process_id' => $this->itemForm, 'behaviours' => [
                    ['source_path' => 'po_number', 'target_path' => 'code'],
                    ['source_path' => 'main.title', 'target_path' => 'name'],
                    ['source_path' => '=0', 'target_path' => 'unit_price'],
                ]],
            ],
            'transitions' => [
                ['source' => null, 'target' => 'order', 'criteria' => $orderCriteria],
                ['source' => 'order', 'target' => 'item', 'criteria' => [['field' => 'notes', 'op' => 'contains', 'value' => 'chair']]],
            ],
        ])->assertOk()->assertJsonCount(2, 'steps')->assertJsonCount(2, 'transitions');
    }

    private function request(string $title, array $values = []): TestResponse
    {
        return $this->postJson("/api/metas/{$this->requestForm}/data", $values + [
            'request_no' => 'PR-F-'.substr(md5($title), 0, 6), 'title' => $title, 'item_id' => DB::table('proc_items')->value('id'),
            'quantity' => 1, 'status' => 'draft', 'needed_by' => '2026-11-01',
        ]);
    }

    public function test_saving_the_main_form_runs_the_next_forms_with_their_values(): void
    {
        $this->saveFlow();

        $response = $this->request('Office chairs')->assertCreated()
            ->assertJsonPath('_processes.0.process', 'Purchase order form')
            ->assertJsonPath('_processes.1.process', 'Item form');

        $order = DB::table('proc_purchase_orders')->find($response->json('_processes.0.id'));
        $this->assertSame('PO-261021001', $order->po_number);
        $this->assertSame($this->supplier, $order->supplier_id);
        $this->assertSame('2026-11-01', $order->order_date);
        $this->assertSame('Office chairs', $order->notes);

        $item = DB::table('proc_items')->find($response->json('_processes.1.id'));
        $this->assertSame('PO-261021001', $item->code);
        $this->assertSame('Office chairs', $item->name);
    }

    public function test_a_transition_whose_criteria_fail_stops_there(): void
    {
        $this->saveFlow([['field' => 'department', 'op' => '=', 'value' => 'IT']]);

        $this->request('Laptops', ['department' => 'HR'])->assertCreated()->assertJsonMissingPath('_processes');
        $this->assertSame(0, DB::table('proc_purchase_orders')->where('notes', 'Laptops')->count());

        // The order runs, but its notes don't mention chairs, so no item is made.
        $this->request('Laptops for IT', ['department' => 'IT'])->assertCreated()->assertJsonCount(1, '_processes');
    }

    public function test_a_step_that_refuses_undoes_the_whole_save(): void
    {
        $this->saveFlow();
        $items = DB::table('proc_items')->count();

        $this->request('No date', ['needed_by' => null])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'Purchase order form: '));

        $this->assertSame(0, DB::table('proc_purchase_requests')->where('title', 'No date')->count());
        $this->assertSame($items, DB::table('proc_items')->count());
    }

    public function test_the_builder_refuses_a_form_running_itself_or_a_loose_transition(): void
    {
        $this->putJson("/api/metas/{$this->requestForm}/process-flow", [
            'steps' => [['key' => 'a', 'process_id' => $this->requestForm]],
            'transitions' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('steps.0.process_id');

        $this->putJson("/api/metas/{$this->requestForm}/process-flow", [
            'steps' => [['key' => 'a', 'process_id' => $this->orderForm]],
            'transitions' => [['source' => 'a', 'target' => 'nowhere']],
        ])->assertUnprocessable();
    }
}
