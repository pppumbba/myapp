<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Models\MetaProcessTransition;
use App\Models\MetaProcessWorkflow;
use App\Support\Access;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A form's process flow, for the builder: its steps (meta_process_workflow) with their
 * behaviours, and the transitions between them. Saved whole, like a page's layout.
 */
class ProcessFlowController extends Controller
{
    /** GET /metas/{meta}/process-flow */
    public function show(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $this->requireForm($meta);

        return response()->json($this->flow($meta));
    }

    /**
     * PUT /metas/{meta}/process-flow
     * {steps: [{key, process_id, behaviours: [{source_path, target_path}]}],
     *  transitions: [{source: key|null, target: key, criteria: [{field, op, value}]}]}
     * Keys only tie transitions to steps within this request; the old flow is replaced.
     */
    public function save(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $this->requireForm($meta);

        $data = $request->validate([
            'steps' => ['present', 'array'],
            'steps.*.key' => ['required', 'string', 'distinct'],
            'steps.*.process_id' => ['required', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($meta) {
                if ((int) $value === $meta->id) {
                    $fail('A form cannot run itself.');
                } elseif (! Meta::whereKey($value)->whereHas('type', fn ($q) => $q->whereIn('code', ['form', 'stage_query']))->exists()) {
                    $fail('Pick a form or a stage query.');
                }
            }],
            'steps.*.behaviours' => ['array'],
            'steps.*.behaviours.*.source_path' => ['required', 'string', 'max:255'],
            'steps.*.behaviours.*.target_path' => ['required', 'string', 'max:255'],
            'transitions' => ['present', 'array'],
            'transitions.*.source' => ['nullable', 'string'],
            'transitions.*.target' => ['required', 'string'],
            'transitions.*.criteria' => ['nullable', 'array'],
            'transitions.*.criteria.*.field' => ['required', 'string', 'max:255'],
            'transitions.*.criteria.*.op' => ['required', 'in:=,!=,>,<,>=,<=,contains,empty,not_empty'],
            'transitions.*.criteria.*.value' => ['nullable'],
        ]);

        $keys = array_column($data['steps'], 'key');
        foreach ($data['transitions'] as $i => $t) {
            abort_unless(in_array($t['target'], $keys, true) && ($t['source'] === null || in_array($t['source'], $keys, true)), 422, 'Transition '.($i + 1).' points at a step that is not in the flow.');
            abort_if($t['source'] === $t['target'], 422, 'Transition '.($i + 1).' leads a step to itself.');
        }

        DB::transaction(function () use ($meta, $data) {
            MetaProcessWorkflow::where('main_process_id', $meta->id)->delete();
            $ids = [];
            foreach ($data['steps'] as $order => $s) {
                $step = MetaProcessWorkflow::create(['main_process_id' => $meta->id, 'process_id' => $s['process_id'], 'order_number' => $order]);
                $step->behaviours()->createMany($s['behaviours'] ?? []);
                $ids[$s['key']] = $step->id;
            }
            foreach ($data['transitions'] as $t) {
                MetaProcessTransition::create([
                    'main_process_id' => $meta->id,
                    'source_id' => $t['source'] === null ? null : $ids[$t['source']],
                    'target_id' => $ids[$t['target']],
                    'criteria' => $t['criteria'] ?? [],
                ]);
            }
        });

        return response()->json($this->flow($meta));
    }

    /** @return array{steps: mixed, transitions: mixed} */
    private function flow(Meta $meta): array
    {
        return [
            'steps' => MetaProcessWorkflow::where('main_process_id', $meta->id)->with(['process:id,code,name', 'behaviours'])->orderBy('order_number')->orderBy('id')->get(),
            'transitions' => MetaProcessTransition::where('main_process_id', $meta->id)->orderBy('id')->get(),
        ];
    }

    private function requireForm(Meta $meta): void
    {
        $meta->loadMissing('type');
        abort_unless($meta->type?->code === 'form', 422, 'Only forms have process flows.');
    }
}
