<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tags saying what metas are for (see Tag). Anyone signed in reads them and adds new ones;
 * admins rename, recolor and remove them. Which metas carry a tag is set on the meta
 * (MetaController, tag_ids).
 */
class TagController extends Controller
{
    /** GET /tags — every tag, with how many metas carry it. */
    public function index(): JsonResponse
    {
        return response()->json(Tag::withCount('metas')->orderBy('name')->get());
    }

    /** POST /tags — a name already taken (in any case) gives back that tag instead of a second one. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'color' => ['nullable', Rule::in(Tag::COLORS)],
        ]);
        $name = trim(preg_replace('/\s+/', ' ', $data['name']));
        abort_if($name === '', 422, 'A tag needs a name.');

        $existing = Tag::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        if ($existing) {
            return response()->json($existing);
        }

        $tag = Tag::create(['name' => $name, 'color' => $data['color'] ?? null, 'created_by' => $request->user()->id]);

        return response()->json($tag, 201);
    }

    /** PUT /tags/{tag} */
    public function update(Request $request, Tag $tag): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('tags', 'name')->ignore($tag->id)],
            'color' => ['sometimes', 'nullable', Rule::in(Tag::COLORS)],
        ]);
        $tag->update($data);

        return response()->json($tag->loadCount('metas'));
    }

    /** DELETE /tags/{tag} — the metas carrying it just lose it. */
    public function destroy(Request $request, Tag $tag): JsonResponse
    {
        Access::requireAdmin($request->user());
        $tag->delete();

        return response()->json(null, 204);
    }
}
