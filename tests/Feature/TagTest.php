<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaType;
use App\Models\Tag;
use App\Models\User;
use App\Support\MetaVersions;
use Database\Seeders\MetaTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Tags saying what metas are for (tags, meta_tag_map). */
class TagTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetaTypeSeeder::class);
        $this->admin = User::factory()->admin()->create();
    }

    private function grid(string $code): Meta
    {
        return Meta::create(['code' => $code, 'name' => ucfirst($code), 'type_id' => MetaType::where('code', 'datagrid')->value('id'), 'is_active' => true]);
    }

    public function test_anyone_adds_a_tag_and_a_taken_name_gives_back_the_same_tag(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $id = $this->postJson('/api/tags', ['name' => '  Procurement ', 'color' => 'blue'])->assertCreated()->assertJsonPath('name', 'Procurement')->json('id');
        $this->postJson('/api/tags', ['name' => 'procurement'])->assertOk()->assertJsonPath('id', $id);
        $this->postJson('/api/tags', ['name' => 'Finance', 'color' => 'neon'])->assertUnprocessable()->assertJsonValidationErrors(['color']);

        // Renaming and removing are for admins.
        $this->putJson("/api/tags/{$id}", ['name' => 'Buying'])->assertForbidden();
        $this->deleteJson("/api/tags/{$id}")->assertForbidden();
    }

    public function test_a_meta_carries_several_tags_set_with_its_other_fields(): void
    {
        Sanctum::actingAs($this->admin);
        [$a, $b, $c] = collect(['Procurement', 'Report', 'Finance'])->map(fn ($n) => Tag::create(['name' => $n]));

        $id = $this->postJson('/api/metas', ['code' => 'orders', 'name' => 'Orders', 'type_id' => MetaType::where('code', 'datagrid')->value('id'), 'tag_ids' => [$a->id, $b->id]])
            ->assertCreated()->assertJsonPath('tags.*.name', ['Procurement', 'Report'])->json('id');

        // Leaving tag_ids out keeps them; sending them replaces them.
        $this->putJson("/api/metas/{$id}", ['name' => 'All orders'])->assertOk()->assertJsonCount(2, 'tags');
        $this->putJson("/api/metas/{$id}", ['tag_ids' => [$c->id]])->assertOk()->assertJsonPath('tags.*.name', ['Finance']);
        $this->putJson("/api/metas/{$id}", ['tag_ids' => [999]])->assertUnprocessable()->assertJsonValidationErrors(['tag_ids.0']);

        $this->getJson('/api/metas')->assertOk()->assertJsonPath('0.tags.0.name', 'Finance');
        $this->getJson('/api/tags')->assertOk()->assertJsonPath('0.name', 'Finance')->assertJsonPath('0.metas_count', 1);
    }

    public function test_removing_a_tag_leaves_its_metas_and_versions_keep_the_tags(): void
    {
        Sanctum::actingAs($this->admin);
        $meta = $this->grid('suppliers');
        $tag = Tag::create(['name' => 'Master data']);
        $meta->tags()->sync([$tag->id]);
        $version = MetaVersions::save($meta->id, $this->admin, 'Tagged');
        $meta->tags()->sync([]);

        MetaVersions::restore($version, $this->admin);
        $this->assertSame(['Master data'], $meta->tags()->pluck('name')->all());

        $this->deleteJson("/api/tags/{$tag->id}")->assertNoContent();
        $this->assertModelExists($meta);
        $this->assertSame(0, $meta->tags()->count());
    }
}
