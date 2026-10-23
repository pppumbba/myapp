<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use App\Models\Widget;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Page metas, built from widgets (and widget_template metas, a composite widget's parts,
 * built the same way). A page's params place widget metas: ref_meta_id is the
 * widget meta, order_number its place, parent_id the section it sits in, json_config.w its
 * width on the 12-column grid. A widget meta's widget_id is its library entry and its
 * json_config the prop values. Widgets made in the page builder belong to their page
 * (their ref_meta_id is the page) and go when the page stops using them; a widget meta
 * made on its own can be placed on several pages.
 */
class PageController extends Controller
{
    public const WIDTHS = [3, 4, 6, 8, 9, 12];

    private const MAX_DEPTH = 3;

    /** GET /metas/{meta}/page — the page and its widgets as a tree, leaving out widgets the user may not view. */
    public function show(Request $request, Meta $meta): JsonResponse
    {
        Access::require($request->user(), $meta, 'view');
        $this->requirePage($meta);

        $params = MetaParam::where('main_meta_id', $meta->id)->where('is_show', true)->orderBy('order_number')->get();
        $widgets = Meta::with(['widget', 'links', 'notificationLink'])->whereIn('id', $params->pluck('ref_meta_id')->filter())->get()
            ->filter(fn (Meta $w) => Access::can($request->user(), $w, 'view'))->keyBy('id');

        return response()->json([
            'meta' => $meta->load('type'),
            'items' => $this->tree($params, $widgets, null),
        ]);
    }

    /**
     * PUT /metas/{meta}/page — saves the whole layout. items: [{param_id?, meta_id?,
     * widget_id, config, w, label?, children?}]. An item with meta_id updates that widget
     * meta (on every page that shows it); without one, a widget meta is made for the page.
     * Params left out are removed, and so are this page's own widgets nothing uses anymore.
     */
    public function save(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $this->requirePage($meta);
        $data = $request->validate([
            'items' => ['present', 'array'],
        ]);

        $widgets = Widget::all()->keyBy('id');
        $widgetType = MetaType::where('code', 'widget')->value('id');
        abort_unless($widgetType, 422, 'The widget meta type is missing. Run the MetaTypeSeeder.');

        DB::transaction(function () use ($request, $meta, $data, $widgets, $widgetType) {
            $existing = MetaParam::where('main_meta_id', $meta->id)->get()->keyBy('id');
            $kept = [];
            $order = 0;

            $walk = function (array $items, ?int $parentId, int $depth) use (&$walk, &$kept, &$order, $request, $meta, $existing, $widgets, $widgetType) {
                abort_if($depth > self::MAX_DEPTH, 422, 'Sections can only be nested '.self::MAX_DEPTH.' deep.');
                foreach ($items as $i => $item) {
                    abort_unless(is_array($item), 422, 'Each item must be an object.');
                    $widget = $widgets->get((int) ($item['widget_id'] ?? 0));
                    abort_unless($widget, 422, "Item {$i} has no widget from the library.");
                    $config = is_array($item['config'] ?? null) ? $item['config'] : [];
                    $children = is_array($item['children'] ?? null) ? $item['children'] : [];
                    abort_if($children && ! $widget->isContainer(), 422, "A {$widget->name} can't hold other widgets; put them in a section.");
                    abort_if($widget->template_meta_id === $meta->id, 422, "The {$widget->name} widget can't be one of its own parts.");

                    $widgetMeta = $this->widgetMeta($request, $meta, $item, $widget, $config, $widgetType);

                    $w = (int) ($item['w'] ?? 12);
                    $values = [
                        'parent_id' => $parentId,
                        'order_number' => ++$order,
                        'ref_meta_id' => $widgetMeta->id,
                        'label_name' => is_string($item['label'] ?? null) ? mb_substr($item['label'], 0, 255) : null,
                        'is_show' => true,
                        'json_config' => ['w' => in_array($w, self::WIDTHS, true) ? $w : 12],
                    ];
                    $param = isset($item['param_id']) ? $existing->get((int) $item['param_id']) : null;
                    if ($param && ! in_array($param->id, $kept, true)) {
                        $param->update($values);
                    } else {
                        $param = MetaParam::create($values + [
                            'main_meta_id' => $meta->id,
                            'field_path' => $widgetMeta->code,
                            'param_name' => $widgetMeta->code,
                            'data_type' => 'widget',
                        ]);
                    }
                    $kept[] = $param->id;

                    $walk($children, $param->id, $depth + 1);
                }
            };
            $walk($data['items'], null, 1);

            // Kept params already point at their new parents, so cascading deletes spare them.
            MetaParam::where('main_meta_id', $meta->id)->whereNotIn('id', $kept)->get()->each->delete();

            // The page's own widgets that nothing places anymore.
            Meta::where('ref_meta_id', $meta->id)->where('type_id', $widgetType)
                ->whereNotExists(fn ($q) => $q->from('meta_params')->whereColumn('meta_params.ref_meta_id', 'metas.id'))
                ->delete();
        });

        return $this->show($request, $meta);
    }

    /** The widget meta an item places: an existing one updated, or a new one owned by the page. */
    private function widgetMeta(Request $request, Meta $page, array $item, Widget $widget, array $config, int $widgetType): Meta
    {
        $name = is_string($item['name'] ?? null) && trim($item['name']) !== '' ? mb_substr(trim($item['name']), 0, 255) : null;

        if (isset($item['meta_id'])) {
            $existing = Meta::whereKey((int) $item['meta_id'])->where('type_id', $widgetType)->first();
            abort_unless($existing, 422, 'A widget on this page no longer exists. Reload the page builder.');
            abort_if($existing->id === $page->id, 422, 'A page cannot contain itself.');
            $existing->update(['widget_id' => $widget->id, 'json_config' => $config, 'updated_by' => $request->user()->id] + ($name ? ['name' => $name] : []));

            return $existing;
        }

        $base = $page->code.'_'.$widget->code;
        $code = $base;
        for ($n = 2; Meta::where('code', $code)->exists(); $n++) {
            $code = "{$base}_{$n}";
        }

        return Meta::create([
            'code' => $code,
            'name' => $name ?? $widget->name.' on '.$page->name,
            'type_id' => $widgetType,
            'widget_id' => $widget->id,
            'ref_meta_id' => $page->id,
            'json_config' => $config,
            'is_active' => true,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function tree(Collection $params, Collection $widgets, ?int $parentId): array
    {
        return $params->where('parent_id', $parentId)->values()
            ->filter(fn (MetaParam $p) => $widgets->has($p->ref_meta_id) && $widgets[$p->ref_meta_id]->widget)
            ->map(fn (MetaParam $p) => [
                'param_id' => $p->id,
                'label' => $p->label_name,
                'w' => (int) ($p->json_config['w'] ?? 12),
                'meta' => $widgets[$p->ref_meta_id]->only(['id', 'code', 'name', 'ref_meta_id', 'json_config', 'widget_id']),
                'widget' => $widgets[$p->ref_meta_id]->widget,
                'children' => $this->tree($params, $widgets, $p->id),
            ])->values()->all();
    }

    /** Pages, and composite widgets' templates, are laid out from widgets. */
    private function requirePage(Meta $meta): void
    {
        $meta->loadMissing('type');
        abort_unless(in_array($meta->type?->code, ['page', 'widget_template'], true), 422, 'Only page metas are built from widgets.');
    }
}
