<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What the signed-in user may do, so the app can hide what they can't. */
class AccessController extends Controller
{
    /**
     * GET /user/access: whether they are an admin, their roles, and for every meta with
     * permission rows what they may do with it (metas not listed are open).
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'is_admin' => $user->isAdmin(),
            'roles' => $user->roles()->orderBy('name')->get(['roles.id', 'code', 'name']),
            'metas' => (object) Access::metaRules($user),
        ]);
    }
}
