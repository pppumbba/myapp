<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\User;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A form's scripts (load, event, before and after save, shared variables and functions) are saved with its meta. */
class FormScriptTest extends TestCase
{
    use RefreshDatabase;

    private Meta $form;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->form = Meta::where('code', 'proc_order_form')->firstOrFail();
    }

    public function test_the_order_form_comes_with_scripts_that_keep_its_total(): void
    {
        $this->getJson("/api/metas/{$this->form->id}")
            ->assertOk()
            ->assertJsonPath('var_fnc_expression_string', fn ($js) => str_contains($js, 'function orderTotal()'))
            ->assertJsonPath('event_expression_string', fn ($js) => str_contains($js, "set('total_amount', orderTotal())"))
            ->assertJsonPath('before_save_expression_string', fn ($js) => str_contains($js, 'error('))
            ->assertJsonPath('after_save_expression_string', null);
    }

    public function test_scripts_can_be_changed_and_cleared(): void
    {
        $scripts = [
            'load_expression_string' => "set('notes', 'Hello');",
            'event_expression_string' => "if (field === 'order_date') set('expected_date', get('order_date'));",
            'before_save_expression_string' => "if (!get('supplier_id')) error('Pick a supplier.');",
            'after_save_expression_string' => "message('Saved order ' + row.po_number);",
            'var_fnc_expression_string' => 'const VAT = 0.1;',
        ];
        $this->putJson("/api/metas/{$this->form->id}", $scripts)->assertOk()->assertJson($scripts);
        $this->assertSame('const VAT = 0.1;', $this->form->fresh()->var_fnc_expression_string);

        $this->putJson("/api/metas/{$this->form->id}", ['event_expression_string' => null])->assertOk()->assertJsonPath('event_expression_string', null);
        $this->putJson("/api/metas/{$this->form->id}", ['load_expression_string' => ['not', 'text']])
            ->assertUnprocessable()->assertJsonValidationErrors(['load_expression_string']);
    }
}
