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

/** Filters pick values with the lookup or list of the same field on the grid's form. */
class FilterLookupsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function metaId(string $code): int
    {
        return Meta::where('code', $code)->value('id');
    }

    /** @return array<string, array> the grid's params by field_path */
    private function params(string $grid): array
    {
        return collect($this->getJson("/api/metas/{$this->metaId($grid)}/params")->assertOk()->json())->keyBy('field_path')->all();
    }

    public function test_a_plain_filter_borrows_the_form_fields_list_or_lookup(): void
    {
        $requests = $this->params('proc_requests_grid');
        $this->assertSame(['draft', 'submitted', 'approved', 'rejected', 'ordered'], $requests['status']['filter_as']['options']);
        $this->assertArrayNotHasKey('filter_as', $requests['title']);

        $orders = $this->params('proc_supplier_orders_grid');
        $this->assertTrue($orders['supplier_id']['filter_as']['is_select']);
        $this->assertSame($this->metaId('proc_suppliers_grid'), $orders['supplier_id']['filter_as']['ref_meta_id']);
    }

    public function test_a_filter_with_its_own_list_keeps_it(): void
    {
        $grid = $this->metaId('proc_requests_grid');
        MetaParam::where(['main_meta_id' => $grid, 'field_path' => 'status'])->first()->update(['json_config' => ['options' => ['draft', 'approved']]]);

        $this->assertArrayNotHasKey('filter_as', $this->params('proc_requests_grid')['status']);
    }

    public function test_a_borrowed_filter_matches_exactly_instead_of_containing(): void
    {
        $grid = $this->metaId('proc_requests_grid');
        DB::table('proc_purchase_requests')->update(['status' => 'submitted']);
        $item = DB::table('proc_items')->value('id');
        DB::table('proc_purchase_requests')->insert([
            ['request_no' => 'PR-X-1', 'title' => 'Exact', 'item_id' => $item, 'quantity' => 1, 'status' => 'draft'],
            ['request_no' => 'PR-X-2', 'title' => 'Not quite', 'item_id' => $item, 'quantity' => 1, 'status' => 'draft (old)'],
        ]);

        $this->getJson("/api/metas/{$grid}/data?filter[status]=draft")->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.request_no', 'PR-X-1');
    }
}
