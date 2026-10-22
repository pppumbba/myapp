<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use App\Models\Schedule;
use App\Models\User;
use App\Support\ScheduledForms;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Stage queries: SQL that changes data, with named parameters, run by hand, in flows and on schedules. */
class StageQueryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        $this->admin = User::factory()->admin()->create();
        Sanctum::actingAs($this->admin);
    }

    private function meta(string $code): Meta
    {
        return Meta::where('code', $code)->firstOrFail();
    }

    /** @param list<array{0: string, 1?: array}> $params */
    private function stageQuery(string $sql, array $params = []): Meta
    {
        $meta = Meta::create(['code' => 'sq_'.uniqid(), 'name' => 'Test stage query', 'type_id' => MetaType::where('code', 'stage_query')->value('id'), 'query_string' => $sql, 'is_active' => true]);
        foreach ($params as $i => $param) {
            [$name, $extra] = $param + [1 => []];
            MetaParam::create(['main_meta_id' => $meta->id, 'order_number' => $i + 1, 'field_path' => $name, 'param_name' => $name, 'data_type' => 'string'] + $extra);
        }

        return $meta;
    }

    private function drafts(): int
    {
        return DB::table('proc_purchase_requests')->where('status', 'draft')->count();
    }

    public function test_a_dry_run_counts_the_rows_and_a_run_changes_them(): void
    {
        $query = $this->meta('proc_close_overdue_drafts');
        DB::table('proc_purchase_requests')->where('status', 'draft')->update(['needed_by' => '2020-01-01']);
        $drafts = $this->drafts();
        $this->assertGreaterThan(0, $drafts);

        $this->postJson("/api/metas/{$query->id}/run", ['params' => ['before' => '2021-01-01'], 'dry_run' => true])
            ->assertOk()->assertJson(['affected' => $drafts, 'dry_run' => true]);
        $this->assertSame($drafts, $this->drafts());

        $this->postJson("/api/metas/{$query->id}/run", ['params' => ['before' => '2019-01-01']])->assertOk()->assertJsonPath('affected', 0);
        $this->postJson("/api/metas/{$query->id}/run", ['params' => ['before' => '2021-01-01']])->assertOk()->assertJsonPath('affected', $drafts);
        $this->assertSame(0, $this->drafts());
    }

    public function test_parameters_take_their_default_and_required_ones_must_be_filled(): void
    {
        $query = $this->stageQuery('UPDATE proc_purchase_requests SET notes = :note WHERE request_no = :request_no', [
            ['note', ['default_value' => 'from default']],
            ['request_no', ['is_required' => true, 'label_name' => 'Request']],
        ]);
        $no = DB::table('proc_purchase_requests')->value('request_no');

        $this->postJson("/api/metas/{$query->id}/run", ['params' => []])->assertStatus(422)->assertJsonPath('message', 'Fill in Request.');
        $this->postJson("/api/metas/{$query->id}/run", ['params' => ['request_no' => $no]])->assertOk()->assertJsonPath('affected', 1);
        $this->assertSame('from default', DB::table('proc_purchase_requests')->where('request_no', $no)->value('notes'));
    }

    public function test_only_statements_that_change_data_and_no_system_tables(): void
    {
        foreach ([
            'DROP TABLE proc_items' => 'must be an INSERT, UPDATE, DELETE or REPLACE',
            'SELECT * FROM proc_items' => 'must be an INSERT, UPDATE, DELETE or REPLACE',
            "UPDATE proc_items SET name = 'x'; ALTER TABLE proc_items ADD x INT" => 'Statement 2 must be',
            "UPDATE users SET name = 'x'" => 'system table',
            'DELETE FROM proc_items WHERE id IN (SELECT meta_id FROM meta_permissions)' => 'system table',
        ] as $sql => $message) {
            $response = $this->postJson('/api/metas/'.$this->stageQuery($sql)->id.'/run')->assertStatus(422);
            $this->assertStringContainsString($message, $response->json('message'), $sql);
        }

        // Words inside text are only text.
        $this->postJson('/api/metas/'.$this->stageQuery("UPDATE proc_items SET name = 'users; drop' WHERE id = 0")->id.'/run')->assertOk();
    }

    public function test_statements_run_together_and_a_failing_one_undoes_the_rest(): void
    {
        $query = $this->stageQuery("UPDATE proc_purchase_requests SET notes = 'first';\nUPDATE no_such_table SET x = 1");

        $response = $this->postJson("/api/metas/{$query->id}/run")->assertStatus(422);
        $this->assertStringStartsWith('Statement 2 could not run', $response->json('message'));
        $this->assertSame(0, DB::table('proc_purchase_requests')->where('notes', 'first')->count());
    }

    public function test_a_grid_toolbar_button_can_run_a_stage_query_but_not_a_form(): void
    {
        $grid = $this->meta('proc_items_grid');
        $query = $this->meta('proc_close_overdue_drafts');
        $form = $this->meta('proc_item_form');

        $id = $this->postJson("/api/metas/{$grid->id}/actions", [
            'action' => 'stage_query', 'label' => 'Close drafts', 'form_meta_id' => $query->id,
        ])->assertCreated()->assertJsonPath('form.id', $query->id)->json('id');

        $this->postJson("/api/metas/{$grid->id}/actions", ['action' => 'stage_query', 'label' => 'X', 'form_meta_id' => $form->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['form_meta_id']);
        $this->putJson("/api/actions/{$id}", ['form_meta_id' => $form->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['form_meta_id']);
    }

    public function test_a_form_runs_a_stage_query_in_its_process_flow(): void
    {
        $form = $this->meta('proc_request_form');
        $query = $this->stageQuery("UPDATE proc_purchase_requests SET notes = 'flowed by ' || :who WHERE request_no = :request_no", [['request_no'], ['who']]);
        $this->putJson("/api/metas/{$form->id}/process-flow", [
            'steps' => [['key' => 'a', 'process_id' => $query->id, 'behaviours' => [['source_path' => 'request_no', 'target_path' => 'request_no'], ['source_path' => '=the flow', 'target_path' => 'who']]]],
            'transitions' => [],
        ])->assertOk();

        $this->postJson("/api/metas/{$form->id}/data", ['request_no' => 'PR-SQ-1', 'title' => 'Desks', 'item_id' => DB::table('proc_items')->value('id'), 'quantity' => 2, 'status' => 'draft'])->assertCreated();

        $this->assertSame('flowed by the flow', DB::table('proc_purchase_requests')->where('request_no', 'PR-SQ-1')->value('notes'));
    }

    public function test_a_schedule_runs_a_stage_query_for_each_grid_row(): void
    {
        $query = $this->stageQuery("UPDATE proc_purchase_requests SET notes = 'scheduled' WHERE id = :id", [['id']]);
        $schedule = Schedule::create(['code' => 'daily', 'name' => 'Every day']);
        $grid = $this->meta('proc_requests_grid');
        $this->postJson("/api/schedules/{$schedule->id}/forms", ['process_id' => $query->id, 'view_id' => $grid->id])->assertCreated();

        $rows = DB::table('proc_purchase_requests')->count();
        $result = ScheduledForms::runSchedule($schedule, $this->admin)[0];

        $this->assertSame([$rows, $rows, [], null], [$result['created'], $result['affected'], $result['failed'], $result['message']]);
        $this->assertSame($rows, DB::table('proc_purchase_requests')->where('notes', 'scheduled')->count());
    }
}
