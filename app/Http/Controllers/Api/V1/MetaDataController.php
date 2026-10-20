<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Models\MetaParam;
use App\Support\Access;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Reads and writes the records behind a datagrid, form, calendar or gantt meta.
 *
 * The meta's table_name says which table, and its params say which columns. Only columns
 * that a param names and that really exist are ever selected or written, and system tables
 * are refused, so a meta can't be pointed at users or the metas themselves. A meta with a
 * query_string reads from that query instead, as written: whoever edits metas is trusted
 * with SQL. Get metas (fill) decide the values a form opens with.
 */
class MetaDataController extends Controller
{
    public const BLOCKED_TABLES = [
        'users', 'password_reset_tokens', 'sessions', 'personal_access_tokens',
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations',
        'metas', 'meta_params', 'meta_types', 'meta_actions',
        'roles', 'user_roles', 'meta_permissions', 'wfm_status_permissions', 'user_notifications',
        'wfm_status', 'wfm_transition', 'wfm_status_log',
    ];

    /** Columns the database fills in itself; forms never write them. */
    private const MANAGED_COLUMNS = ['id', 'created_at', 'updated_at'];

    /**
     * GET /metas/{meta}/data?search=&filter[field]=&sort=&dir=&page=&per_page=&from=&to=
     *
     * A meta with a query_string reads from that query; its :named parameters take their
     * values from filter[name] (and the built-ins :user_id and :today), always bound, never
     * pasted into the SQL. Other filter[field] values filter the params marked is_criteria:
     * text contains, dates on that day, everything else equal. Until every
     * is_mandatory_criteria filter has a value, no rows come back and missing_filters says
     * which are needed. search matches the text criteria; from/to (dates) keep the records
     * whose json_config start_field..end_field span overlaps [from, to).
     */
    public function index(Request $request, Meta $meta): JsonResponse
    {
        Access::require($request->user(), $meta, 'view');
        $source = $this->source($meta);
        $filters = $this->filters($request, 'filter');
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $missing = $source['params']->where('is_mandatory_criteria', true)
            ->filter(fn (MetaParam $p) => ($filters[$p->field_path] ?? '') === '')
            ->map(fn (MetaParam $p) => $p->label_name ?: $p->param_name)->values();
        if ($missing->isNotEmpty()) {
            return response()->json(['data' => [], 'current_page' => 1, 'last_page' => 1, 'per_page' => $perPage, 'total' => 0, 'missing_filters' => $missing]);
        }

        $tokens = [];
        if ($source['query']) {
            [$sql, $bindings, $tokens] = $this->named($source['query'], $filters + $this->builtins($request));
            $query = DB::query()->fromRaw("({$sql}) as q", $bindings)->select('q.*');
        } else {
            $query = DB::table($source['table'])->select($source['columns']);
        }
        $this->withoutHidden($query, $source, $request);

        $criteria = $source['params']->where('is_criteria', true)
            ->filter(fn (MetaParam $p) => in_array($p->field_path, $source['columns'], true) && ! in_array($p->field_path, $tokens, true));

        $search = trim((string) $request->query('search', ''));
        $texts = $criteria->filter(fn (MetaParam $p) => $this->isText($p))->pluck('field_path');
        if ($search !== '' && $texts->isNotEmpty()) {
            $query->where(function ($q) use ($texts, $search) {
                foreach ($texts as $column) {
                    $q->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }

        foreach ($criteria as $param) {
            $value = $filters[$param->field_path] ?? '';
            if ($value === '') {
                continue;
            }
            $column = $param->field_path;
            match (true) {
                in_array($param->data_type, ['date', 'datetime'], true) => $query->whereDate($column, $value),
                $param->data_type === 'boolean' => $query->where($column, filter_var($value, FILTER_VALIDATE_BOOLEAN)),
                $this->isText($param) => $query->where($column, 'like', '%'.$value.'%'),
                default => $query->where($column, $value),
            };
        }

        $this->inRange($query, $meta, $source['columns'], $request->query('from'), $request->query('to'));

        $sort = $request->query('sort');
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';
        if (is_string($sort) && in_array($sort, $source['columns'], true)) {
            $query->orderBy($sort, $dir);
        } elseif (! $source['query'] || $source['params']->contains('field_path', $source['key'])) {
            // A query's rows keep their own order unless the key is one of its columns.
            $query->orderByDesc($source['key']);
        }

        return response()->json($this->read(fn () => $query->paginate($perPage)));
    }

    /**
     * GET /metas/{meta}/fill?params[name]=value
     * Runs a get meta's query with these named parameters (plus :user_id and :today) and
     * returns its first row: the values a form opens with. No row gives {}.
     */
    public function fill(Request $request, Meta $meta): JsonResponse
    {
        Access::require($request->user(), $meta, 'view');
        $meta->loadMissing('type');
        abort_unless($meta->type?->code === 'get', 422, 'Only get metas fill forms.');
        $sql = trim((string) $meta->query_string);
        abort_if($sql === '', 422, 'This get meta has no query yet.');

        [$sql, $bindings] = $this->named($sql, $this->filters($request, 'params') + $this->builtins($request));
        $row = $this->read(fn () => DB::selectOne($sql, $bindings));

        return response()->json($row ?? (object) []);
    }

    /** Text the user types (not a lookup or a list of options), matched with "contains". */
    private function isText(MetaParam $param): bool
    {
        return in_array($param->data_type, ['string', 'text'], true) && ! $param->is_select && ! is_array($param->json_config['options'] ?? null);
    }

    /** Scalar values of an array query parameter like filter[...] or params[...]. */
    private function filters(Request $request, string $name): array
    {
        $values = $request->query($name, []);
        if (! is_array($values)) {
            return [];
        }

        return array_map(fn ($v) => trim((string) $v), array_filter($values, fn ($v) => is_scalar($v)));
    }

    /** Named parameters every query can use. */
    private function builtins(Request $request): array
    {
        return ['user_id' => $request->user()?->id, 'today' => now()->toDateString()];
    }

    /**
     * Turns :name parameters into ? placeholders with their values in order, so a name can be
     * used more than once. Text inside '...' and casts like ::date are left alone. A name
     * without a value (or with an empty one) is bound as null, so a query can say
     * (:status IS NULL OR status = :status).
     *
     * @return array{0: string, 1: array, 2: string[]} the SQL, its bindings, the names used
     */
    private function named(string $sql, array $values): array
    {
        $bindings = [];
        $names = [];
        $parts = preg_split("/('(?:[^']|'')*')/", $sql, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                continue;
            }
            $parts[$i] = preg_replace_callback('/(?<![:\w]):([A-Za-z_]\w*)/', function ($m) use (&$bindings, &$names, $values) {
                $names[] = $m[1];
                $value = $values[$m[1]] ?? null;
                $bindings[] = $value === '' ? null : $value;

                return '?';
            }, $part);
        }

        return [implode('', $parts), $bindings, array_values(array_unique($names))];
    }

    /** GET /metas/{meta}/data/{id} */
    public function show(Request $request, Meta $meta, int $id): JsonResponse
    {
        Access::require($request->user(), $meta, 'view');
        $source = $this->source($meta, true);
        $row = $this->withoutHidden(DB::table($source['table'])->select($source['columns'])->where($source['key'], $id), $source, $request)->first();

        abort_if(! $row, 404, 'Record not found.');

        return response()->json($this->withDetails($row, $this->details($meta), $id));
    }

    /** POST /metas/{meta}/data, with each detail's lines under its field_path. */
    public function store(Request $request, Meta $meta): JsonResponse
    {
        Access::require($request->user(), $meta, 'create');
        $source = $this->source($meta, true);
        $details = $this->details($meta);
        [$data, $lines] = $this->validated($request, $source, $details);
        $data += $this->timestamps($source['table'], true);

        $id = $this->write(fn () => DB::transaction(function () use ($source, $details, $data, $lines) {
            $id = DB::table($source['table'])->insertGetId($data);
            $this->saveLines($source, $details, $lines, $id);

            return $id;
        }));

        return response()->json($this->withDetails($this->find($source, $id), $details, $id), 201);
    }

    /** PUT/PATCH /metas/{meta}/data/{id}. A detail sent replaces all of that record's lines. */
    public function update(Request $request, Meta $meta, int $id): JsonResponse
    {
        Access::require($request->user(), $meta, 'edit');
        $source = $this->source($meta, true);
        abort_unless($this->withoutHidden(DB::table($source['table'])->where($source['key'], $id), $source, $request)->exists(), 404, 'Record not found.');

        $details = $this->details($meta);
        [$data, $lines] = $this->validated($request, $source, $details, $id);
        $data += $this->timestamps($source['table'], false);

        $this->write(fn () => DB::transaction(function () use ($source, $details, $data, $lines, $id) {
            DB::table($source['table'])->where($source['key'], $id)->update($data);
            $this->saveLines($source, $details, $lines, $id);
        }));

        return response()->json($this->withDetails($this->find($source, $id), $details, $id));
    }

    /** DELETE /metas/{meta}/data/{id}. Its detail lines go with it. */
    public function destroy(Request $request, Meta $meta, int $id): JsonResponse
    {
        Access::require($request->user(), $meta, 'delete');
        $source = $this->source($meta, true);
        abort_unless($this->withoutHidden(DB::table($source['table'])->where($source['key'], $id), $source, $request)->exists(), 404, 'Record not found.');
        $details = $this->details($meta);
        $deleted = $this->write(fn () => DB::transaction(function () use ($source, $details, $id) {
            foreach ($details as $detail) {
                DB::table($detail['source']['table'])->where($detail['foreign_key'], $id)->delete();
            }

            return DB::table($source['table'])->where($source['key'], $id)->delete();
        }));

        abort_if(! $deleted, 404, 'Record not found.');

        return response()->json(null, 204);
    }

    /**
     * Leaves out records sitting in a workflow status the user may not see. Applies when the
     * table (or, for a query, its columns) has a status_id; records without a status stay.
     */
    private function withoutHidden($query, array $source, Request $request): mixed
    {
        $hasStatus = $source['table'] ? Schema::hasColumn($source['table'], 'status_id') : in_array('status_id', $source['columns'], true);
        $hidden = $hasStatus ? Access::hiddenStatuses($request->user()) : [];
        if ($hidden) {
            $query->where(fn ($q) => $q->whereNull('status_id')->orWhereNotIn('status_id', $hidden));
        }

        return $query;
    }

    private function find(array $source, int $id): object
    {
        return DB::table($source['table'])->select($source['columns'])->where($source['key'], $id)->first();
    }

    /**
     * A form's detail params: data_type "detail", ref_meta_id = the form describing one line,
     * json_config.foreign_key = the line column pointing back at this record. Optionally
     * json_config.amount = [column, column] multiplied per line and summed into total_field.
     *
     * @return array<int, array{field: string, source: array, foreign_key: string, amount: ?array, total_field: ?string}>
     */
    private function details(Meta $meta): array
    {
        if ($meta->type?->code !== 'form') {
            return [];
        }

        return MetaParam::where('main_meta_id', $meta->id)->where('data_type', 'detail')->whereNotNull('ref_meta_id')
            ->orderBy('order_number')->get()
            ->map(function (MetaParam $param) {
                $child = Meta::find($param->ref_meta_id);
                $fk = $param->json_config['foreign_key'] ?? null;
                abort_unless($child && is_string($fk), 422, "The detail \"{$param->field_path}\" needs a line form and a foreign_key.");
                $source = $this->source($child, true);
                abort_unless(Schema::hasColumn($source['table'], $fk), 422, "The detail \"{$param->field_path}\" points at a column that does not exist.");
                $amount = $param->json_config['amount'] ?? null;

                return [
                    'field' => $param->field_path,
                    'source' => $source,
                    'foreign_key' => $fk,
                    'amount' => is_array($amount) && count($amount) === 2 ? array_values(array_intersect($amount, $source['columns'])) : null,
                    'total_field' => $param->json_config['total_field'] ?? null,
                ];
            })->all();
    }

    private function withDetails(object $row, array $details, int $id): object
    {
        foreach ($details as $detail) {
            $s = $detail['source'];
            $row->{$detail['field']} = DB::table($s['table'])->select($s['columns'])
                ->where($detail['foreign_key'], $id)->orderBy($s['key'])->get();
        }

        return $row;
    }

    /** Updates lines that kept their id, adds new ones, removes the rest, then fills totals. */
    private function saveLines(array $source, array $details, array $lines, int $id): void
    {
        foreach ($details as $detail) {
            if (! array_key_exists($detail['field'], $lines)) {
                continue;
            }
            $s = $detail['source'];
            $fk = $detail['foreign_key'];
            $existing = DB::table($s['table'])->where($fk, $id)->pluck($s['key'])->map(fn ($k) => (int) $k)->all();
            $kept = [];

            foreach ($lines[$detail['field']] as $line) {
                $lineId = isset($line[$s['key']]) ? (int) $line[$s['key']] : null;
                unset($line[$s['key']]);
                $line[$fk] = $id;

                if ($lineId && in_array($lineId, $existing, true)) {
                    DB::table($s['table'])->where($s['key'], $lineId)->update($line + $this->timestamps($s['table'], false));
                    $kept[] = $lineId;
                } else {
                    DB::table($s['table'])->insert($line + $this->timestamps($s['table'], true));
                }
            }

            DB::table($s['table'])->where($fk, $id)->whereIn($s['key'], array_diff($existing, $kept))->delete();

            $total = $detail['total_field'];
            if ($detail['amount'] && count($detail['amount']) === 2 && is_string($total) && in_array($total, $source['columns'], true)) {
                [$a, $b] = $detail['amount'];
                $sum = DB::table($s['table'])->where($fk, $id)->get([$a, $b])->sum(fn ($l) => (float) $l->$a * (float) $l->$b);
                DB::table($source['table'])->where($source['key'], $id)->update([$total => round($sum, 2)]);
            }
        }
    }

    /** Keeps records overlapping [from, to). One with no end is treated as ending when it starts. */
    private function inRange($query, Meta $meta, array $columns, mixed $from, mixed $to): void
    {
        $start = $meta->json_config['start_field'] ?? null;
        if (! is_string($start) || ! in_array($start, $columns, true)) {
            return;
        }
        $end = $meta->json_config['end_field'] ?? null;
        $end = is_string($end) && in_array($end, $columns, true) ? $end : null;

        if (is_string($to) && strtotime($to) !== false) {
            $query->where($start, '<', date('Y-m-d', strtotime($to)));
        }
        if (is_string($from) && strtotime($from) !== false) {
            $from = date('Y-m-d', strtotime($from));
            $query->where(fn ($q) => $end
                ? $q->where($end, '>=', $from)->orWhere(fn ($q) => $q->whereNull($end)->where($start, '>=', $from))
                : $q->where($start, '>=', $from));
        }
    }

    /**
     * Works out where a meta's records come from, the readable columns and the primary key,
     * or stops with 422 if the meta can't hold data.
     *
     * Reading a meta with a query_string uses the query as written (builders are trusted
     * with it); its columns are the params' field paths. Writing always goes to the meta's
     * table_name, so $forWrite ignores the query.
     *
     * @return array{query: ?string, table: ?string, columns: string[], key: string, params: Collection}
     */
    private function source(Meta $meta, bool $forWrite = false): array
    {
        $meta->loadMissing('type');
        abort_unless(in_array($meta->type?->code, ['datagrid', 'form', 'calendar', 'gantt'], true), 422, 'Only datagrid, form, calendar and gantt metas have data.');

        $sql = trim((string) $meta->query_string);
        if ($sql !== '' && ! $forWrite) {
            // Only plain names can be columns: they end up in WHERE and ORDER BY of the outer query.
            $params = MetaParam::where('main_meta_id', $meta->id)->orderBy('order_number')->get()
                ->filter(fn (MetaParam $p) => ! in_array($p->data_type, ['detail', 'subgrid'], true) && preg_match('/^[A-Za-z_]\w*$/', $p->field_path))
                ->values();
            $key = $params->firstWhere('is_primary', true)?->field_path ?? 'id';

            return ['query' => $sql, 'table' => null, 'columns' => $params->pluck('field_path')->push($key)->unique()->values()->all(), 'key' => $key, 'params' => $params];
        }

        $table = trim((string) $meta->table_name);
        abort_if($sql !== '' && $table === '', 422, 'This meta reads from a query, so it can only be changed through a form with a table.');
        abort_if($table === '', 422, 'This meta has no database table set.');
        abort_if(in_array(strtolower($table), self::BLOCKED_TABLES, true), 422, 'This table cannot be used by a meta.');
        abort_unless(Schema::hasTable($table), 422, "The table \"{$table}\" does not exist.");

        $existing = Schema::getColumnListing($table);
        $params = MetaParam::where('main_meta_id', $meta->id)->orderBy('order_number')->get()
            ->filter(fn (MetaParam $p) => in_array($p->field_path, $existing, true))
            ->values();

        $key = $params->firstWhere('is_primary', true)?->field_path ?? 'id';
        abort_unless(in_array($key, $existing, true), 422, 'This table has no primary key column.');

        $columns = $params->pluck('field_path')->push($key)->unique()->values()->all();

        // A grid run by a workflow (its ref_meta_id) also reads each record's status. Forms
        // never write it: only moves through the workflow do.
        if (! in_array('status_id', $columns, true) && in_array('status_id', $existing, true) && $meta->ref_meta_id
            && Meta::whereKey($meta->ref_meta_id)->whereHas('type', fn ($q) => $q->where('code', 'workflow'))->exists()) {
            $columns[] = 'status_id';
        }

        return ['query' => null, 'table' => $table, 'columns' => $columns, 'key' => $key, 'params' => $params];
    }

    /**
     * Checks the record and each detail's lines against their params.
     *
     * @return array{0: array, 1: array<string, array>} the record's values, and lines by detail field
     */
    private function validated(Request $request, array $source, array $details, ?int $id = null): array
    {
        $rules = $this->rules($source, $id);
        foreach ($details as $detail) {
            $s = $detail['source'];
            $rules[$detail['field']] = ['sometimes', 'array'];
            $rules["{$detail['field']}.*.{$s['key']}"] = ['nullable', 'integer'];
            $rules += $this->rules($s, null, "{$detail['field']}.*.", [$detail['foreign_key']]);
        }

        $valid = $request->validate($rules);

        $data = array_intersect_key($valid, array_flip($source['columns']));
        $this->checkOptions($source['params'], $data);

        $lines = [];
        foreach ($details as $detail) {
            if (! array_key_exists($detail['field'], $valid)) {
                continue;
            }
            $lines[$detail['field']] = array_values($valid[$detail['field']]);
            foreach ($lines[$detail['field']] as $i => $line) {
                $this->checkOptions($detail['source']['params'], $line, "{$detail['field']}.{$i}.");
            }
        }

        return [$data, $lines];
    }

    /**
     * Validation rules come from the params: required, data type, min/max, unique and lookups.
     * Lines use a prefix like "lines.*."; unique isn't checked there, since each line would
     * need its own exception.
     */
    private function rules(array $source, ?int $id, string $prefix = '', array $skip = []): array
    {
        $rules = [];

        foreach ($source['params'] as $param) {
            $column = $param->field_path;
            if ($param->is_primary || in_array($column, self::MANAGED_COLUMNS, true) || in_array($column, $skip, true)) {
                continue;
            }

            $rule = [$id ? 'sometimes' : null, $param->is_required ? 'required' : 'nullable'];

            switch ($param->data_type) {
                case 'integer':
                    $rule[] = 'integer';
                    break;
                case 'decimal':
                    $rule[] = 'numeric';
                    break;
                case 'boolean':
                    $rule[] = 'boolean';
                    break;
                case 'date':
                case 'datetime':
                    $rule[] = 'date';
                    break;
                case 'text':
                    $rule[] = 'string';
                    break;
                default:
                    $rule[] = 'string';
                    $rule[] = 'max:255';
            }

            if (in_array($param->data_type, ['integer', 'decimal'], true)) {
                if (is_numeric($param->min_value)) {
                    $rule[] = 'min:'.$param->min_value;
                }
                if (is_numeric($param->max_value)) {
                    $rule[] = 'max:'.$param->max_value;
                }
            }

            if ($param->is_unique && $prefix === '') {
                $rule[] = Rule::unique($source['table'], $column)->ignore($id, $source['key']);
            }

            // A lookup must point at a record that exists in the lookup meta's table.
            if ($param->is_select && $param->ref_meta_id) {
                $refTable = Meta::whereKey($param->ref_meta_id)->value('table_name');
                if ($refTable && ! in_array(strtolower($refTable), self::BLOCKED_TABLES, true) && Schema::hasTable($refTable)) {
                    $rule[] = Rule::exists($refTable, 'id');
                }
            }

            $rules[$prefix.$column] = array_values(array_filter($rule));
        }

        return $rules;
    }

    /** Lists of allowed values live in json_config.options. */
    private function checkOptions(Collection $params, array $data, string $prefix = ''): void
    {
        foreach ($params as $param) {
            $options = $param->json_config['options'] ?? null;
            $value = $data[$param->field_path] ?? null;
            if (is_array($options) && $value !== null && $value !== '' && ! in_array($value, $options, true)) {
                abort(response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => [$prefix.$param->field_path => ['Pick one of: '.implode(', ', $options).'.']],
                ], 422));
            }
        }
    }

    private function timestamps(string $table, bool $creating): array
    {
        $now = now();
        $values = [];
        if (Schema::hasColumn($table, 'updated_at')) {
            $values['updated_at'] = $now;
        }
        if ($creating && Schema::hasColumn($table, 'created_at')) {
            $values['created_at'] = $now;
        }

        return $values;
    }

    /** Turns database refusals (e.g. deleting a supplier that orders still use) into a readable 422. */
    private function write(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (QueryException $e) {
            $message = str_contains($e->getMessage(), 'foreign key') || str_contains($e->getMessage(), 'FOREIGN KEY')
                ? 'Other records still use this one, so it cannot be changed or deleted.'
                : 'The database refused this change.';
            abort(422, $message);
        }
    }

    /** Runs a read; a query that fails says why, so whoever wrote it can fix it. */
    private function read(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (QueryException $e) {
            abort(422, 'The query could not run: '.($e->errorInfo[2] ?? $e->getPrevious()?->getMessage() ?? 'unknown error').'.');
        }
    }
}
