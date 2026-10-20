<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MetaController extends Controller
{
    /** GET /metas — every meta, with its type and how many rows it has. */
    public function index(Request $request): JsonResponse
    {
        $metas = Access::viewable(Meta::query(), $request->user())->with(['type:id,code,name', 'refMeta:id,code,name'])
            ->withCount('params')
            ->orderBy('name')
            ->get();

        return response()->json($metas);
    }

    /**
     * GET /metas/options — short list for dropdowns (ref_meta lookups).
     * Defined before the resource routes so "options" isn't read as an id.
     */
    public function options(Request $request): JsonResponse
    {
        $options = Access::viewable(Meta::query(), $request->user())->with('type:id,code')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'type_id'])
            ->map(fn (Meta $m) => [
                'id' => $m->id,
                'code' => $m->code,
                'name' => $m->name,
                'type' => $m->type?->code,
            ]);

        return response()->json($options);
    }

    /** POST /metas */
    public function store(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;

        $meta = Meta::create($data);

        return response()->json($meta->load(['type', 'refMeta']), 201);
    }

    /** GET /metas/{meta} */
    public function show(Request $request, Meta $meta): JsonResponse
    {
        Access::require($request->user(), $meta, 'view');

        return response()->json($meta->load(['type', 'refMeta']));
    }

    /** PUT/PATCH /metas/{meta} */
    public function update(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $this->validated($request, $meta);
        $data['updated_by'] = $request->user()->id;

        $meta->update($data);

        return response()->json($meta->fresh()->load(['type', 'refMeta']));
    }

    /**
     * DELETE /metas/{meta}
     * Its params are removed (cascadeOnDelete). Metas that pointed at it lose the link (nullOnDelete).
     */
    public function destroy(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $meta->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, ?Meta $current = null): array
    {
        $data = $request->validate([
            'code' => [
                $current ? 'sometimes' : 'required', 'string', 'max:100',
                Rule::unique('metas', 'code')->ignore($current?->id),
            ],
            'name' => [$current ? 'sometimes' : 'required', 'string', 'max:255'],
            'label_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'type_id' => ['nullable', 'integer', 'exists:meta_types,id'],
            'ref_meta_id' => ['nullable', 'integer', 'exists:metas,id'],
            'description' => ['nullable', 'string'],
            'table_name' => ['nullable', 'string', 'max:255'],
            'query_string' => ['nullable', 'string'],
            'json_config' => ['nullable', 'array'],
        ]);

        if (! empty($data['ref_meta_id']) && $current && (int) $data['ref_meta_id'] === $current->id) {
            abort(422, 'A meta cannot reference itself.');
        }

        return $data;
    }
}
