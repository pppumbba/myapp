<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use App\Models\Schedule;
use App\Models\User;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScheduledFormsTest extends TestCase
{
    use RefreshDatabase;

    private Meta $restockGrid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());

        // A grid shaped like the request form: one restock request per item.
        $this->restockGrid = Meta::create([
            'code' => 'restock_grid', 'name' => 'Items to restock', 'type_id' => MetaType::where('code', 'datagrid')->value('id'),
            'query_string' => "SELECT id, id AS item_id, 'RS-' || code AS request_no, 'Restock ' || name AS title, 2 AS quantity, 'draft' AS status FROM proc_items",
        ]);
        foreach (['id', 'item_id', 'request_no', 'title', 'quantity', 'status'] as $i => $field) {
            MetaParam::create(['main_meta_id' => $this->restockGrid->id, 'param_name' => $field, 'field_path' => $field, 'data_type' => 'string', 'is_primary' => $field === 'id', 'order_number' => $i]);
        }
    }

    private function scheduleWithRestock(string $code = 'daily'): Schedule
    {
        $schedule = Schedule::create(['code' => $code, 'name' => 'Restock']);
        $schedule->forms()->create(['process_id' => Meta::where('code', 'proc_request_form')->value('id'), 'view_id' => $this->restockGrid->id]);

        return $schedule;
    }

    public function test_a_schedule_code_must_be_a_keyword_or_a_cron_expression(): void
    {
        $this->postJson('/api/schedules', ['code' => 'sometimes', 'name' => 'Bad'])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->postJson('/api/schedules', ['code' => '0 6 * * 1', 'name' => 'Monday mornings'])
            ->assertCreated()
            ->assertJsonPath('expression', '0 6 * * 1');
        $this->postJson('/api/schedules', ['code' => 'daily', 'name' => 'Every night'])
            ->assertCreated()
            ->assertJsonPath('expression', '0 0 * * *');
    }

    public function test_only_a_form_can_run_over_only_a_datagrid(): void
    {
        $schedule = Schedule::create(['code' => 'daily', 'name' => 'Restock']);
        $form = Meta::where('code', 'proc_request_form')->value('id');

        $this->postJson("/api/schedules/{$schedule->id}/forms", ['process_id' => $this->restockGrid->id, 'view_id' => $form])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['process_id', 'view_id']);

        $this->postJson("/api/schedules/{$schedule->id}/forms", ['process_id' => $form, 'view_id' => $this->restockGrid->id])
            ->assertCreated()
            ->assertJsonPath('process.code', 'proc_request_form');
    }

    public function test_running_saves_the_form_once_per_grid_row_and_reports_rows_it_refuses(): void
    {
        $schedule = $this->scheduleWithRestock();
        $items = DB::table('proc_items')->get();

        $this->postJson("/api/schedules/{$schedule->id}/run")
            ->assertOk()
            ->assertJsonPath('results.0.created', $items->count())
            ->assertJsonPath('results.0.failed', []);

        $item = $items->first();
        $request = DB::table('proc_purchase_requests')->where('request_no', "RS-{$item->code}")->first();
        $this->assertSame("Restock {$item->name}", $request->title);
        $this->assertSame($item->id, $request->item_id);
        $this->assertSame(2, $request->quantity);

        // The request numbers are taken now, so a second run saves nothing and says why.
        $this->postJson("/api/schedules/{$schedule->id}/run")
            ->assertOk()
            ->assertJsonPath('results.0.created', 0)
            ->assertJsonCount($items->count(), 'results.0.failed')
            ->assertJsonPath('results.0.failed.0.message', 'The request no has already been taken.');
    }

    public function test_the_command_runs_only_the_schedules_that_are_due(): void
    {
        $this->scheduleWithRestock('daily');
        $before = DB::table('proc_purchase_requests')->count();

        $this->travelTo(now()->setTime(13, 30));
        $this->artisan('forms:run-scheduled')->assertSuccessful();
        $this->assertSame($before, DB::table('proc_purchase_requests')->count());

        $this->travelTo(now()->addDay()->startOfDay());
        $this->artisan('forms:run-scheduled')->assertSuccessful();
        $this->assertSame($before + DB::table('proc_items')->count(), DB::table('proc_purchase_requests')->count());
    }

    public function test_only_admins_manage_schedules(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/schedules')->assertForbidden();
        $this->postJson('/api/schedules', ['code' => 'daily', 'name' => 'Restock'])->assertForbidden();
    }
}
