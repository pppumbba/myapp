<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SessionValue;
use App\Support\Access;
use App\Support\SessionValues;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Custom session values (see SessionValues): defined by admins, read by everyone for themselves. */
class SessionValueController extends Controller
{
    /** GET /user/session: the signed-in user's values, path => value. */
    public function mine(Request $request): JsonResponse
    {
        return response()->json((object) SessionValues::for($request->user()));
    }

    /** POST /user/session/refresh: works them out again now (e.g. after the data behind them changed). */
    public function refresh(Request $request): JsonResponse
    {
        return response()->json((object) SessionValues::refresh($request->user()));
    }

    /** GET /session-values, each with its value for the admin asking. */
    public function index(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());
        $mine = SessionValues::for($request->user());

        return response()->json(SessionValue::orderBy('id')->get()->map(fn (SessionValue $s) => $s->toArray() + ['value' => $mine[$s->path] ?? null]));
    }

    /** POST /session-values */
    public function store(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());

        return response()->json(SessionValue::create($this->validated($request)), 201);
    }

    /** PUT /session-values/{sessionValue} */
    public function update(Request $request, SessionValue $sessionValue): JsonResponse
    {
        Access::requireAdmin($request->user());
        $sessionValue->update($this->validated($request, $sessionValue));

        return response()->json($sessionValue);
    }

    /** DELETE /session-values/{sessionValue} */
    public function destroy(Request $request, SessionValue $sessionValue): JsonResponse
    {
        Access::requireAdmin($request->user());
        $sessionValue->delete();

        return response()->json(null, 204);
    }

    /**
     * POST /session-values/test {query, param}: what the query gives for the admin asking,
     * using the values of the session values defined so far.
     */
    public function test(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $request->validate(['query' => ['required', 'string'], 'param' => ['nullable', 'string', 'max:100']]);

        return response()->json(SessionValues::run($data['query'], $data['param'] ?? null, $request->user(), SessionValues::compute($request->user())));
    }

    private function validated(Request $request, ?SessionValue $current = null): array
    {
        return $request->validate([
            // A :name in SQL: letters, digits and underscores, not starting with a digit.
            'path' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z_]\w*$/', Rule::notIn(['user_id', 'today', 'userid', 'email']), Rule::unique('session_values', 'path')->ignore($current?->id)],
            'query' => ['required', 'string', 'regex:/^\s*(select|with)\b/i'],
            'param' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z_]\w*$/'],
        ], [
            'path.regex' => 'Use letters, digits and underscores, e.g. sessionemployeeid.',
            'path.not_in' => 'That name is already built in.',
            'query.regex' => 'A session value is read with a SELECT (or WITH … SELECT) query.',
            'param.regex' => 'Use a name like userid or another session value\'s path.',
        ]);
    }
}
