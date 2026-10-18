<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MetaRequest;
use App\Models\Meta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class MetaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $metas = Meta::query()
            ->with(['type', 'refMeta'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->string('search');
                $q->where(fn ($q) => $q->where('code', 'like', "%{$s}%")
                                       ->orWhere('name', 'like', "%{$s}%"));
            })
            ->when($request->filled('type_id'), fn ($q) => $q->where('type_id', $request->integer('type_id')))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json($metas);
    }

    public function store(MetaRequest $request): JsonResponse
    {
    $userId = $request->user()->id;
    $data   = $request->validated();
    $params = $data['params'] ?? [];
    unset($data['params']);

    $meta = DB::transaction(function () use ($data, $params, $userId) {
        $meta = Meta::create([
            ...$data,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        if ($params) {
            $meta->params()->createMany($params);
        }

        return $meta;
    });

    return response()->json(
        $meta->load(['type', 'refMeta', 'params.refMeta']),
        201
    );
}

    public function show(Meta $meta): JsonResponse
{
    return response()->json(
        $meta->load([
            'type',
            'refMeta',
            'creator',
            'updater',
            'params' => fn ($q) => $q->orderBy('order_number'),
            'params.refMeta',
        ])
    );
}

public function update(MetaRequest $request, Meta $meta): JsonResponse
{
    $data   = $request->validated();
    $params = $data['params'] ?? null; // null means "don't touch params"
    unset($data['params']);

    DB::transaction(function () use ($meta, $data, $params, $request) {
        if ($data) {
            $meta->update([
                ...$data,
                'updated_by' => $request->user()->id,
            ]);
        }

        if ($params !== null) {
            foreach ($params as $item) {
                $id = $item['id'] ?? null;
                unset($item['id']);

                // Only match a param that belongs to this meta
                $param = $id
                    ? $meta->params()->whereKey($id)->first()
                    : null;

                if ($param) {
                    $param->update($item);          // exists: update
                } else {
                    $meta->params()->create($item); // new or unknown id: insert
                }
            }
        }
    });

    return response()->json(
        $meta->fresh()->load(['type', 'refMeta', 'params.refMeta'])
    );
}

    public function destroy(Meta $meta): Response
{
    DB::transaction(function () use ($meta) {
        // Explicit, in case foreign keys aren't enforced on this database.
        // With cascadeOnDelete this is already handled by the database.
        $meta->params()->delete();
        $meta->delete();
    });

    return response()->noContent();
}
}