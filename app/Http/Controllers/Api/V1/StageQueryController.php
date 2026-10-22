<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Support\StageQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Runs stage queries (see StageQuery) by hand: from their screen, or the builder's try-out. */
class StageQueryController extends Controller
{
    /**
     * POST /metas/{meta}/run {params: {name: value}, dry_run: bool}
     * A dry run changes nothing and says how many rows would change.
     */
    public function run(Request $request, Meta $meta): JsonResponse
    {
        $data = $request->validate([
            'params' => ['nullable', 'array'],
            'params.*' => ['nullable'],
            'dry_run' => ['boolean'],
        ]);

        return response()->json(StageQuery::run($meta, array_filter($data['params'] ?? [], 'is_scalar'), $request->user(), (bool) ($data['dry_run'] ?? false)));
    }
}
