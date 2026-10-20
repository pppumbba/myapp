<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MetaType;
use Illuminate\Http\JsonResponse;

class MetaTypeController extends Controller
{
    /** GET /meta-types — active types for the type dropdown. */
    public function index(): JsonResponse
    {
        return response()->json(
            MetaType::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'description'])
        );
    }
}
