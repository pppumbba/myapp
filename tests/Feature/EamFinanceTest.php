<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\User;
use Database\Seeders\EamDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EamFinanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(EamDemoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function asset(string $name): object
    {
        return DB::table('eam_assets')->where('name', $name)->first();
    }

    private function draft(string $type, string $asset, array $values): int
    {
        static $n = 0;

        return DB::table('eam_asset_transactions')->insertGetId($values + [
            'txn_no' => 'TEST-'.(++$n), 'txn_type' => $type, 'asset_id' => $this->asset($asset)->id,
            'txn_date' => now()->toDateString(), 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function postTransaction(int $txn): void
    {
        $this->postJson('/api/metas/'.Meta::where('code', 'eam_post_transaction')->value('id').'/run', ['params' => ['txn_id' => $txn]])->assertOk();
    }

    /** @return array<string, array{debit: float, credit: float}> by account */
    private function journal(int $txn): array
    {
        return DB::table('eam_journal_entries')->where('transaction_id', $txn)->get()
            ->groupBy('account_code')->map(fn ($l) => ['debit' => (float) $l->sum('debit'), 'credit' => (float) $l->sum('credit')])->all();
    }

    public function test_the_seeded_ledger_balances_and_matches_the_asset_balances(): void
    {
        $this->assertEqualsWithDelta((float) DB::table('eam_journal_entries')->sum('debit'), (float) DB::table('eam_journal_entries')->sum('credit'), 0.001);

        // Fixed asset accounts in the journal add up to the assets' book values.
        $net = DB::table('eam_journal_entries')->whereIn('account_code', ['1500', '1510', '1580', '1590'])->selectRaw('SUM(debit - credit) AS net')->value('net');
        $this->assertEqualsWithDelta((float) DB::table('eam_assets')->sum('carrying_amount'), (float) $net, 0.001);

        $compressor = $this->asset('Air compressor');
        $this->assertGreaterThan(0, (float) $compressor->accumulated_impairment);
        $this->assertEquals(0, (float) $compressor->revaluation_surplus);
        $this->assertGreaterThan(0, (float) $this->asset('Rooftop AC unit')->revaluation_surplus);
        $this->assertSame(3, DB::table('eam_asset_transactions')->where('status', 'draft')->count());

        // The scrapped printer and the sold heater are off the books, and the clearing account is empty.
        foreach (['Old office printer', 'Warehouse heater'] as $name) {
            $this->assertSame('disposed', $this->asset($name)->status);
            $this->assertEquals(0, (float) $this->asset($name)->carrying_amount);
        }
        $this->assertEqualsWithDelta(0, (float) DB::table('eam_journal_entries')->where('account_code', '1700')->selectRaw('SUM(debit - credit) AS net')->value('net'), 0.001);
    }

    public function test_capitalization_adds_cost_and_credits_the_offset_account(): void
    {
        $before = (float) $this->asset('Forklift')->carrying_amount;
        $txn = $this->draft('capitalization', 'Forklift', ['amount' => 5000000, 'offset_account' => '1600']);

        $this->postTransaction($txn);

        $this->assertEqualsWithDelta($before + 5000000, (float) $this->asset('Forklift')->carrying_amount, 0.001);
        $this->assertSame(['1500' => ['debit' => 5000000.0, 'credit' => 0.0], '1600' => ['debit' => 0.0, 'credit' => 5000000.0]], $this->journal($txn));
        $row = DB::table('eam_asset_transactions')->find($txn);
        $this->assertSame('posted', $row->status);
        $this->assertEqualsWithDelta($before, (float) $row->book_value_before, 0.001);
        $this->assertEqualsWithDelta($before + 5000000, (float) $row->book_value_after, 0.001);
    }

    public function test_depreciation_posts_to_expense_and_accumulated_depreciation(): void
    {
        $before = $this->asset('Forklift');
        $txn = $this->draft('depreciation', 'Forklift', ['amount' => 450000, 'period' => '2099-01']);

        $this->postTransaction($txn);

        $after = $this->asset('Forklift');
        $this->assertEqualsWithDelta((float) $before->accumulated_depreciation + 450000, (float) $after->accumulated_depreciation, 0.001);
        $this->assertEquals(['6100' => ['debit' => 450000.0, 'credit' => 0.0], '1590' => ['debit' => 0.0, 'credit' => 450000.0]], $this->journal($txn));
    }

    public function test_a_revaluation_first_reverses_an_impairment_then_goes_to_the_surplus(): void
    {
        $compressor = $this->asset('Air compressor');
        $impairment = (float) $compressor->accumulated_impairment;
        $txn = $this->draft('revaluation', 'Air compressor', ['new_value' => (float) $compressor->carrying_amount + $impairment + 1000000]);

        $this->postTransaction($txn);

        $after = $this->asset('Air compressor');
        $this->assertEquals(0, (float) $after->accumulated_impairment);
        $this->assertEqualsWithDelta(1000000, (float) $after->revaluation_surplus, 0.001);
        $this->assertEqualsWithDelta($impairment, $this->journal($txn)['7200']['credit'], 0.001);
        $this->assertEqualsWithDelta(1000000, $this->journal($txn)['3200']['credit'], 0.001);
    }

    public function test_a_devaluation_first_uses_up_the_surplus_then_is_an_impairment_loss(): void
    {
        $ac = $this->asset('Rooftop AC unit');
        $surplus = (float) $ac->revaluation_surplus;
        $txn = $this->draft('devaluation', 'Rooftop AC unit', ['new_value' => (float) $ac->carrying_amount - $surplus - 2000000]);

        $this->postTransaction($txn);

        $after = $this->asset('Rooftop AC unit');
        $this->assertEquals(0, (float) $after->revaluation_surplus);
        $this->assertEqualsWithDelta(2000000, (float) $after->accumulated_impairment, 0.001);
        $this->assertEqualsWithDelta($surplus, $this->journal($txn)['3200']['debit'], 0.001);
        $this->assertEqualsWithDelta(2000000, $this->journal($txn)['6200']['debit'], 0.001);
    }

    public function test_posting_twice_or_a_revaluation_below_book_value_changes_nothing(): void
    {
        $txn = $this->draft('capitalization', 'Forklift', ['amount' => 1000]);
        $this->postTransaction($txn);
        $balance = (float) $this->asset('Forklift')->carrying_amount;
        $this->postTransaction($txn);
        $this->assertEqualsWithDelta($balance, (float) $this->asset('Forklift')->carrying_amount, 0.001);
        $this->assertCount(2, DB::table('eam_journal_entries')->where('transaction_id', $txn)->get());

        $wrong = $this->draft('revaluation', 'Forklift', ['new_value' => $balance - 100]);
        $this->postTransaction($wrong);
        $this->assertSame('draft', DB::table('eam_asset_transactions')->where('id', $wrong)->value('status'));
        $this->assertSame(0, DB::table('eam_journal_entries')->where('transaction_id', $wrong)->count());
        $this->assertEqualsWithDelta($balance, (float) $this->asset('Forklift')->carrying_amount, 0.001);
    }

    public function test_a_sale_takes_the_asset_off_the_books_with_its_gain_and_surplus(): void
    {
        $ac = $this->asset('Rooftop AC unit');
        $book = (float) $ac->carrying_amount;
        $surplus = (float) $ac->revaluation_surplus;
        $txn = $this->draft('sale', 'Rooftop AC unit', ['amount' => $book + 3000000, 'offset_account' => '1010', 'counterparty' => 'Buyer LLC']);

        $this->postTransaction($txn);

        $after = $this->asset('Rooftop AC unit');
        $this->assertSame('disposed', $after->status);
        $this->assertSame(now()->toDateString(), $after->disposed_on);
        foreach (['capitalized_cost', 'accumulated_depreciation', 'accumulated_impairment', 'revaluation_amount', 'revaluation_surplus', 'carrying_amount'] as $column) {
            $this->assertEquals(0, (float) $after->{$column}, $column);
        }
        $lines = $this->journal($txn);
        $this->assertEqualsWithDelta($book + 3000000, $lines['1010']['debit'], 0.001);
        $this->assertEqualsWithDelta(3000000, $lines['7300']['credit'], 0.001);
        $this->assertEqualsWithDelta($surplus, $lines['3300']['credit'], 0.001);
        $this->assertEqualsWithDelta($lines['1700']['debit'], $lines['1700']['credit'], 0.001);
        $this->assertEqualsWithDelta(array_sum(array_column($lines, 'debit')), array_sum(array_column($lines, 'credit')), 0.001);
        $this->assertEqualsWithDelta(3000000, (float) DB::table('eam_asset_transactions')->where('id', $txn)->value('pl_amount'), 0.001);
    }

    public function test_a_disposal_writes_off_the_book_value_and_nothing_posts_to_the_asset_after(): void
    {
        $book = (float) $this->asset('Forklift')->carrying_amount;
        $txn = $this->draft('disposal', 'Forklift', ['amount' => 999]);

        $this->postTransaction($txn);

        $this->assertSame('disposed', $this->asset('Forklift')->status);
        $this->assertEqualsWithDelta($book, $this->journal($txn)['6300']['debit'], 0.001);
        $this->assertEquals(0, (float) DB::table('eam_asset_transactions')->where('id', $txn)->value('amount'));

        $late = $this->draft('depreciation', 'Forklift', ['amount' => 1000, 'period' => '2099-02']);
        $this->postTransaction($late);
        $this->assertSame('draft', DB::table('eam_asset_transactions')->where('id', $late)->value('status'));
        $this->assertSame(0, DB::table('eam_journal_entries')->where('transaction_id', $late)->count());
    }

    public function test_a_purchase_credits_the_supplier_and_registering_an_asset_leaves_one_to_post(): void
    {
        $res = $this->postJson('/api/metas/'.Meta::where('code', 'eam_asset_form')->value('id').'/data', [
            'name' => 'Pallet wrapper', 'category_id' => DB::table('eam_categories')->where('name', 'Production machinery')->value('id'),
            'location_id' => DB::table('eam_locations')->where('code', 'WH-1')->value('id'), 'status' => 'in service', 'condition' => 'new',
            'criticality' => 'medium', 'purchase_date' => now()->toDateString(), 'purchase_cost' => 18000000,
        ])->assertCreated();

        $txn = DB::table('eam_asset_transactions')->where('asset_id', $res->json('id'))->first();
        $this->assertSame('purchase', $txn->txn_type);
        $this->assertSame('draft', $txn->status);
        $this->assertStringStartsWith('PUR-', $txn->txn_no);

        $this->postTransaction($txn->id);

        $asset = DB::table('eam_assets')->find($res->json('id'));
        $this->assertEqualsWithDelta(18000000, (float) $asset->carrying_amount, 0.001);
        $this->assertSame(now()->toDateString(), $asset->capitalized_on);
        $this->assertEquals(['1500' => ['debit' => 18000000.0, 'credit' => 0.0], '2100' => ['debit' => 0.0, 'credit' => 18000000.0]], $this->journal($txn->id));
    }

    public function test_a_posted_transaction_cant_be_deleted(): void
    {
        $txn = DB::table('eam_asset_transactions')->where('status', 'posted')->value('id');

        $res = $this->deleteJson('/api/metas/'.Meta::where('code', 'eam_transaction_form')->value('id')."/data/{$txn}");

        $this->assertGreaterThanOrEqual(400, $res->status());
        $this->assertTrue(DB::table('eam_asset_transactions')->where('id', $txn)->exists());
    }
}
