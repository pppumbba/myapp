<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Models\MetaParam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MetaParamController extends Controller
{
    /**
     * GET /metas/{meta}/params
     * Returns the rows of one meta as a nested tree (roots first, children inside).
     */
    public function index(Meta $meta): JsonResponse
    {
        $rows = MetaParam::where('main_meta_id', $meta->id)
            ->orderBy('order_number')
            ->get();

        return response()->json($this->buildTree($rows));
    }

    /** POST /metas/{meta}/params */
    public function store(Request $request, Meta $meta): JsonResponse
    {
        $data = $this->validated($request, $meta);
        $data['main_meta_id'] = $meta->id;

        $param = MetaParam::create($data);

        return response()->json($param, 201);
    }

    /** GET /params/{param} */
    public function show(MetaParam $param): JsonResponse
    {
        return response()->json($param->load('children'));
    }

    /** PUT/PATCH /params/{param} */
    public function update(Request $request, MetaParam $param): JsonResponse
    {
        $data = $this->validated($request, $param->main, $param);

        $param->update($data);

        return response()->json($param->fresh());
    }

    /** DELETE /params/{param} — children are removed too (cascadeOnDelete). */
    public function destroy(MetaParam $param): JsonResponse
    {
        $param->delete();

        return response()->json(null, 204);
    }

    /**
     * Shared validation for store and update.
     * $current is set on update so a row can't become its own ancestor.
     */
    private function validated(Request $request, Meta $owner, ?MetaParam $current = null): array
    {
        $data = $request->validate([
            'order_number' => ['sometimes', 'integer', 'min:0'],
            'parent_id' => [
                'nullable',
                Rule::exists('meta_params', 'id')->where('main_meta_id', $owner->id),
            ],
            'field_path' => ['sometimes', 'string', 'max:255'],
            'param_name' => ['sometimes', 'string', 'max:255'],
            'column_path' => ['nullable', 'string', 'max:255'],
            'label_name' => ['nullable', 'string', 'max:255'],
            'data_type' => ['sometimes', 'string', 'max:50'],
            'data_mask' => ['nullable', 'string', 'max:255'],
            'render_type' => ['nullable', 'string', 'max:50'],
            'icon_cls' => ['nullable', 'string', 'max:255'],
            'ref_meta_id' => ['nullable', 'integer', 'exists:metas,id'],
            'is_primary' => ['sometimes', 'boolean'],
            'is_show' => ['sometimes', 'boolean'],
            'is_required' => ['sometimes', 'boolean'],
            'is_criteria' => ['sometimes', 'boolean'],
            'is_unique' => ['sometimes', 'boolean'],
            'is_merge' => ['sometimes', 'boolean'],
            'is_select' => ['sometimes', 'boolean'],
            'is_mandatory_criteria' => ['sometimes', 'boolean'],
            'default_value' => ['nullable', 'string', 'max:255'],
            'min_value' => ['nullable', 'string', 'max:255'],
            'max_value' => ['nullable', 'string', 'max:255'],
            'column_width' => ['nullable', 'string', 'max:50'],
            'expression_string' => ['nullable', 'string'],
            'json_config' => ['nullable', 'array'],
        ]);

        // A row can't point at itself as its parent, and no row can be placed under its own descendant.
        if (! empty($data['parent_id']) && $current) {
            if ((int) $data['parent_id'] === $current->id || $this->isDescendant((int) $data['parent_id'], $current->id)) {
                abort(422, 'A row cannot be moved under itself or one of its children.');
            }
        }

        // A row can't reference the meta it belongs to (that would open the screen inside itself).
        if (! empty($data['ref_meta_id']) && (int) $data['ref_meta_id'] === $owner->id) {
            abort(422, 'ref_meta_id cannot point at the meta this row belongs to.');
        }

        return $data;
    }

    /** True if $candidateId is $ancestorId or sits anywhere below it. */
    private function isDescendant(int $candidateId, int $ancestorId): bool
    {
        $id = $candidateId;
        $steps = 0;

        while ($id !== null && $steps++ < 1000) {
            if ($id === $ancestorId) {
                return true;
            }
            $id = MetaParam::whereKey($id)->value('parent_id');
        }

        return false;
    }

    /** Turns a flat, ordered list into roots with a `children` array, recursively. */
    private function buildTree($rows, $parentId = null)
    {
        return $rows
            ->where('parent_id', $parentId)
            ->values()
            ->map(function (MetaParam $row) use ($rows) {
                $row->setAttribute('children', $this->buildTree($rows, $row->id));

                return $row;
            });
    }
}
