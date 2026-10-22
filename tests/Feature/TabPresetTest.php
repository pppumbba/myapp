<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\User;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Tabs and menu items hand their screen starting filters, fixed values and form defaults. */
class TabPresetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create(['name' => 'Rita Requester']));
    }

    private function metaId(string $code): int
    {
        return Meta::where('code', $code)->value('id');
    }

    public function test_a_tabs_values_stay_in_its_config(): void
    {
        $tabs = collect($this->getJson("/api/metas/{$this->metaId('proc_requests_tabs')}/params")->assertOk()->json())->keyBy('field_path');

        $this->assertSame(['status' => 'draft'], $tabs['drafts']['json_config']['filters']);
        $this->assertSame(['requested_by' => '{user_name}'], $tabs['mine']['json_config']['fixed']);
        $this->assertNull($tabs['all']['json_config']);
    }

    public function test_a_fixed_value_keeps_the_grid_to_it(): void
    {
        $request = DB::table('proc_purchase_requests')->value('id');
        DB::table('proc_purchase_requests')->where('id', $request)->update(['requested_by' => 'Rita Requester']);

        $this->getJson("/api/metas/{$this->metaId('proc_requests_grid')}/data?scope[requested_by]=".urlencode('Rita Requester'))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $request);
    }
}
