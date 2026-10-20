<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\User;
use App\Models\WfmStatus;
use App\Models\WfmTransition;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Meta $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->workflow = Meta::where('code', 'proc_order_workflow')->firstOrFail();
    }

    private function statusId(string $code): int
    {
        return WfmStatus::where('workflow_meta_id', $this->workflow->id)->where('code', $code)->value('id');
    }

    private function order(string $po): object
    {
        return DB::table('proc_purchase_orders')->where('po_number', $po)->first();
    }

    private function transitionTo(object $order, string $code): int
    {
        $state = $this->getJson("/api/workflows/{$this->workflow->id}/records/{$order->id}")->assertOk()->json();

        return collect($state['transitions'])->firstWhere('next_status.code', $code)['id'];
    }

    public function test_designer_gets_statuses_transitions_and_layout(): void
    {
        $this->getJson("/api/workflows/{$this->workflow->id}")
            ->assertOk()
            ->assertJsonCount(7, 'statuses')
            ->assertJsonCount(10, 'transitions')
            ->assertJsonStructure(['layout' => ['start', 'statuses']]);

        $this->putJson("/api/workflows/{$this->workflow->id}/layout", ['layout' => ['start' => ['x' => 1, 'y' => 2]]])->assertOk();
        $this->assertSame(['x' => 1, 'y' => 2], $this->workflow->fresh()->json_config['layout']['start']);
    }

    public function test_criteria_decide_which_transitions_an_order_gets(): void
    {
        // PO-2026-0103 is a draft for 4,200,000; push it to pending first.
        $order = $this->order('PO-2026-0103');
        $this->postJson("/api/workflows/{$this->workflow->id}/records/{$order->id}/move", ['transition_id' => $this->transitionTo($order, 'pending')])
            ->assertOk()
            ->assertJsonPath('status.code', 'pending');

        $state = $this->getJson("/api/workflows/{$this->workflow->id}/records/{$order->id}")->assertOk()->json();
        $options = collect($state['transitions'])->keyBy('next_status.code');
        $this->assertTrue($options['approved']['allowed']);
        $this->assertFalse($options['director']['allowed']);
        $this->assertSame('Needs: totalAmount > 5000000', $options['director']['reason']);

        $this->postJson("/api/workflows/{$this->workflow->id}/records/{$order->id}/move", ['transition_id' => $options['director']['id']])
            ->assertStatus(422);
        $this->assertSame($this->statusId('pending'), $this->order('PO-2026-0103')->status_id);
    }

    public function test_moves_are_logged_with_time_spent(): void
    {
        $order = $this->order('PO-2026-0103');
        $this->postJson("/api/workflows/{$this->workflow->id}/records/{$order->id}/move", ['transition_id' => $this->transitionTo($order, 'pending')])->assertOk();
        $this->travel(90)->seconds();
        $this->postJson("/api/workflows/{$this->workflow->id}/records/{$order->id}/move", ['transition_id' => $this->transitionTo($order, 'approved')])
            ->assertOk()
            ->assertJsonPath('status.code', 'approved')
            ->assertJsonCount(2, 'log')
            ->assertJsonPath('log.0.prev_status_id', $this->statusId('pending'))
            ->assertJsonPath('log.0.time_spent', fn ($s) => $s >= 90 && $s < 100)
            ->assertJsonPath('log.1.time_spent', null);
    }

    public function test_a_used_status_stays_and_bad_criteria_are_refused(): void
    {
        $order = $this->order('PO-2026-0103');
        $this->postJson("/api/workflows/{$this->workflow->id}/records/{$order->id}/move", ['transition_id' => $this->transitionTo($order, 'pending')])->assertOk();

        $this->deleteJson('/api/wfm-statuses/'.$this->statusId('pending'))->assertStatus(422);

        $this->postJson("/api/workflows/{$this->workflow->id}/transitions", [
            'prev_status_id' => $this->statusId('approved'), 'next_status_id' => $this->statusId('draft'), 'criteria' => 'totalAmount >',
        ])->assertUnprocessable()->assertJsonValidationErrors(['criteria']);

        $this->postJson("/api/workflows/{$this->workflow->id}/transitions", [
            'prev_status_id' => $this->statusId('draft'), 'next_status_id' => $this->statusId('draft'),
        ])->assertStatus(422);

        $id = $this->postJson("/api/workflows/{$this->workflow->id}/transitions", [
            'prev_status_id' => $this->statusId('approved'), 'next_status_id' => $this->statusId('draft'), 'is_transition' => false,
        ])->assertCreated()->json('id');
        $this->assertFalse(WfmTransition::find($id)->is_transition);
    }

    public function test_the_order_grid_reads_status_ids(): void
    {
        $grid = Meta::where('code', 'proc_orders_grid')->value('id');
        $this->assertSame($this->workflow->id, Meta::find($grid)->ref_meta_id);

        $this->getJson("/api/metas/{$grid}/data?filter[status_id]=".$this->statusId('draft'))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.po_number', 'PO-2026-0103');
    }
}
