<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\User;
use Database\Seeders\TenderDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenderWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private int $laptops;

    private int $cleaning;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TenderDemoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->laptops = DB::table('tenders')->where('tender_no', 'TN-2610001')->value('id');
        $this->cleaning = DB::table('tenders')->where('tender_no', 'TN-2610002')->value('id');
    }

    private function metaId(string $code): int
    {
        return Meta::where('code', $code)->value('id');
    }

    public function test_a_scope_keeps_a_grid_to_one_record_even_without_a_criteria_param(): void
    {
        $grid = $this->metaId('tender_items_grid');

        $this->getJson("/api/metas/{$grid}/data?scope[tender_id]={$this->laptops}")->assertOk()->assertJsonPath('total', 3);
        $this->getJson("/api/metas/{$grid}/data?scope[tender_id]={$this->cleaning}")->assertOk()->assertJsonPath('total', 2);

        // A scope the grid can't apply is refused rather than ignored.
        $this->getJson("/api/metas/{$grid}/data?scope[supplier_id]=1")->assertUnprocessable();
    }

    public function test_the_price_matrix_shows_one_tender_with_its_settings(): void
    {
        $matrix = $this->metaId('tender_price_matrix');

        $res = $this->getJson("/api/metas/{$matrix}/matrix?scope[tender_id]={$this->laptops}")->assertOk()
            ->assertJsonCount(3, 'rows')
            ->assertJsonCount(3, 'columns')
            ->assertJsonPath('rows.0.label', 'Laptop 14"')
            ->assertJsonPath('rows.0.weight', 20)
            ->assertJsonPath('best', 'min')
            ->assertJsonPath('total', 'amount')
            ->json();

        $this->assertCount(9, $res['cells']);
        $first = "{$res['rows'][0]['id']}:{$res['columns'][0]['id']}";
        $this->assertEquals(2450000, $res['cells'][$first]);
    }

    public function test_saving_cells_changes_clears_and_adds_values_within_the_tender(): void
    {
        $matrix = $this->metaId('tender_price_matrix');
        $item = DB::table('tender_items')->where('tender_id', $this->laptops)->orderBy('id')->value('id');
        [$first, $second] = DB::table('tender_bidders')->where('tender_id', $this->laptops)->orderBy('id')->pluck('id')->all();
        $newcomer = DB::table('tender_bidders')->insertGetId(['tender_id' => $this->laptops, 'name' => 'Late Bidder LLC']);

        $this->putJson("/api/metas/{$matrix}/matrix", ['scope' => ['tender_id' => $this->laptops], 'cells' => [
            ['row' => $item, 'column' => $first, 'value' => 2400000],
            ['row' => $item, 'column' => $second, 'value' => null],
            ['row' => $item, 'column' => $newcomer, 'value' => 2300000],
        ]])->assertOk()->assertJsonCount(4, 'columns');

        $price = fn (int $bidder) => DB::table('tender_bidder_items')->where(['item_id' => $item, 'bidder_id' => $bidder])->first();
        $this->assertEquals(2400000, $price($first)->unit_price);
        $this->assertNull($price($second)->unit_price);
        // A new value row takes the tender from the scope.
        $this->assertEquals($this->laptops, $price($newcomer)->tender_id);
    }

    public function test_cells_of_another_tender_cannot_be_written(): void
    {
        $matrix = $this->metaId('tender_price_matrix');
        $item = DB::table('tender_items')->where('tender_id', $this->laptops)->value('id');
        $otherBidder = DB::table('tender_bidders')->where('tender_id', $this->cleaning)->value('id');

        $this->putJson("/api/metas/{$matrix}/matrix", ['scope' => ['tender_id' => $this->laptops], 'cells' => [
            ['row' => $item, 'column' => $otherBidder, 'value' => 1],
        ]])->assertUnprocessable()->assertJsonPath('message', 'Value 1 is for a row or column that is not in this matrix.');
    }

    public function test_scores_reject_text_values(): void
    {
        $matrix = $this->metaId('tender_score_matrix');
        $kpi = DB::table('tender_kpis')->where('tender_id', $this->laptops)->value('id');
        $bidder = DB::table('tender_bidders')->where('tender_id', $this->laptops)->value('id');

        $this->putJson("/api/metas/{$matrix}/matrix", ['scope' => ['tender_id' => $this->laptops], 'cells' => [
            ['row' => $kpi, 'column' => $bidder, 'value' => 'great'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('cells.0.value');
    }
}
