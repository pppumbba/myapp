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

/** How many records have each value of a filter, for a grid's sidebar. */
class GridFacetsTest extends TestCase
{
    use RefreshDatabase;

    private int $grid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->grid = Meta::where('code', 'proc_requests_grid')->value('id');
        // Department is a filter of the grid too (it borrows the form's list).
        MetaParam::where(['main_meta_id' => $this->grid, 'field_path' => 'department'])->update(['is_criteria' => true]);
    }

    /** @return array<string, int> value => count */
    private function counts(string $query, string $field): array
    {
        return collect($this->getJson("/api/metas/{$this->grid}/facets?{$query}")->assertOk()->json($field))->pluck('count', 'value')->all();
    }

    private function expected(?string $department = null): array
    {
        return DB::table('proc_purchase_requests')->when($department, fn ($q) => $q->where('department', $department))
            ->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->sortKeys()->all();
    }

    public function test_each_value_is_counted(): void
    {
        $counts = $this->counts('fields[]=status', 'status');
        ksort($counts);

        $this->assertSame($this->expected(), $counts);
    }

    public function test_a_fields_own_filter_leaves_its_counts_but_the_others_narrow_them(): void
    {
        $counts = $this->counts('fields[]=status&filter[status]=draft', 'status');
        ksort($counts);
        $this->assertSame($this->expected(), $counts);

        $department = DB::table('proc_purchase_requests')->value('department');
        $narrowed = $this->counts('fields[]=status&filter[department]='.urlencode($department), 'status');
        ksort($narrowed);
        $this->assertSame($this->expected($department), $narrowed);
    }

    public function test_only_filter_fields_have_facets(): void
    {
        $this->getJson("/api/metas/{$this->grid}/facets?fields[]=quantity")->assertUnprocessable();
    }
}
