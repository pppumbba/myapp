<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Models\MetaSchedule;
use App\Models\Schedule;
use App\Support\Access;
use App\Support\ScheduledForms;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Schedules and the forms they run, managed by admins. The scheduler (routes/console.php)
 * runs the ones that are due every minute; run starts one now.
 */
class ScheduleController extends Controller
{
    /** GET /schedules, each with its forms and when it runs next. */
    public function index(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());

        return response()->json(Schedule::with(['forms.process:id,code,name', 'forms.view:id,code,name'])->orderBy('name')->get());
    }

    /** POST /schedules */
    public function store(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());

        return response()->json(Schedule::create($this->validated($request))->load('forms'), 201);
    }

    /** PUT /schedules/{schedule} */
    public function update(Request $request, Schedule $schedule): JsonResponse
    {
        Access::requireAdmin($request->user());
        $schedule->update($this->validated($request, $schedule));

        return response()->json($schedule->load(['forms.process:id,code,name', 'forms.view:id,code,name']));
    }

    /** DELETE /schedules/{schedule}. Its forms stop running (the rows go with it). */
    public function destroy(Request $request, Schedule $schedule): JsonResponse
    {
        Access::requireAdmin($request->user());
        $schedule->delete();

        return response()->json(null, 204);
    }

    /** POST /schedules/{schedule}/forms {process_id: a form or stage query, view_id: a datagrid} */
    public function addForm(Request $request, Schedule $schedule): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $request->validate([
            'process_id' => ['required', 'integer', $this->ofType('form', 'stage_query')],
            'view_id' => ['required', 'integer', $this->ofType('datagrid')],
        ]);

        $link = $schedule->forms()->create($data);

        return response()->json($link->load(['process:id,code,name', 'view:id,code,name']), 201);
    }

    /** DELETE /meta-schedules/{metaSchedule} */
    public function removeForm(Request $request, MetaSchedule $metaSchedule): JsonResponse
    {
        Access::requireAdmin($request->user());
        $metaSchedule->delete();

        return response()->json(null, 204);
    }

    /** POST /schedules/{schedule}/run: runs its forms now, as the signed-in admin, due or not. */
    public function run(Request $request, Schedule $schedule): JsonResponse
    {
        Access::requireAdmin($request->user());

        return response()->json(['results' => ScheduledForms::runSchedule($schedule, $request->user())]);
    }

    /** @return array{code?: string, name?: string} */
    private function validated(Request $request, ?Schedule $current = null): array
    {
        $req = $current ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$req, 'string', 'max:100', Rule::unique('schedules', 'code')->ignore($current?->id),
                function (string $attribute, mixed $value, Closure $fail) {
                    if (Schedule::expressionFor((string) $value) === null) {
                        $fail('Use '.implode(', ', array_keys(Schedule::KEYWORDS)).' or a cron expression like "0 6 * * 1".');
                    }
                }],
            'name' => [$req, 'string', 'max:255'],
        ]);
    }

    /** A rule: the id is a meta of one of these types. */
    private function ofType(string ...$types): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($types) {
            if (! Meta::whereKey($value)->whereHas('type', fn ($q) => $q->whereIn('code', $types))->exists()) {
                $fail('Pick a '.implode(' or a ', str_replace('_', ' ', $types)).'.');
            }
        };
    }
}
