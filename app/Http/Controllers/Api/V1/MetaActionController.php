<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Models\MetaAction;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The toolbar buttons of a datagrid (see MetaAction). */
class MetaActionController extends Controller
{
    /** GET /metas/{meta}/actions */
    public function index(Request $request, Meta $meta): JsonResponse
    {
        Access::require($request->user(), $meta, 'view');

        return response()->json(
            MetaAction::where('grid_meta_id', $meta->id)
                ->with(['form:id,code,name', 'get:id,code,name'])
                ->orderBy('order_number')->orderBy('id')
                ->get()
        );
    }

    /** POST /metas/{meta}/actions */
    public function store(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $this->validated($request);
        $data['grid_meta_id'] = $meta->id;
        $data['order_number'] ??= (int) MetaAction::where('grid_meta_id', $meta->id)->max('order_number') + 1;

        return response()->json(MetaAction::create($data)->load(['form:id,code,name', 'get:id,code,name']), 201);
    }

    /** PUT /actions/{action} */
    public function update(Request $request, MetaAction $action): JsonResponse
    {
        Access::requireAdmin($request->user());
        $action->update($this->validated($request, true));

        return response()->json($action->load(['form:id,code,name', 'get:id,code,name']));
    }

    /** DELETE /actions/{action} */
    public function destroy(Request $request, MetaAction $action): JsonResponse
    {
        Access::requireAdmin($request->user());
        $action->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'action' => [$req, Rule::in(MetaAction::ACTIONS)],
            'label' => [$req, 'string', 'max:255'],
            'icon_cls' => ['nullable', 'string', 'max:50'],
            // Every button works through a form: it opens it, or (delete) removes the row from its table.
            'form_meta_id' => [$req, 'integer', 'exists:metas,id'],
            'get_meta_id' => ['nullable', 'integer', 'exists:metas,id'],
            'needs_row' => ['sometimes', 'boolean'],
            'show_in_row' => ['sometimes', 'boolean'],
            'order_number' => ['sometimes', 'integer', 'min:1'],
            'json_config' => ['nullable', 'array'],
            'json_config.params' => ['sometimes', 'array'],
            'json_config.params.*' => ['string', 'max:255'],
        ]);
    }
}
