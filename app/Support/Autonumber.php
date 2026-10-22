<?php

namespace App\Support;

use App\Models\MetaParam;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Numbers a field fills in itself when a record is added, from its param's
 * json_config.autonumber:
 *
 *   {
 *     "format": "PO-{yy}{MM}{dd}{seq:3}",
 *     "rules": [{"field": "book_type_id", "value": 3, "format": "SPO-{yy}{MM}{dd}{seq:3}"}],
 *     "editable": false
 *   }
 *
 * The first rule whose field has that value (or one of a list of values) picks the format,
 * else format. {yyyy} {yy} {MM} {dd} are today's date, {seq:N} the running number padded
 * to N digits (exactly one per format), and any other {name} the record's value of that
 * field. The number counts separately for each text around it, so PO-261021001 is followed
 * by PO-261021002, the next day starts at PO-261022001, and SPO- has its own count.
 *
 * Numbers come from the autonumbers table, locked while a record is saved, so two people
 * saving at once never get the same one; a number typed or imported higher than the
 * counter moves it on. Not editable (the default): the field is never taken from the form.
 * Editable: a typed value is kept, and a blank one is numbered.
 */
class Autonumber
{
    private const TOKEN = '/\{(\w+)(?::(\d+))?\}/';

    /** The param's autonumber settings, or null when it isn't one. */
    public static function config(MetaParam $param): ?array
    {
        $config = $param->json_config['autonumber'] ?? null;

        return is_array($config) && is_string($config['format'] ?? null) ? $config : null;
    }

    /** Autonumbered and not editable: forms and imports never write it. */
    public static function isLocked(MetaParam $param): bool
    {
        $config = self::config($param);

        return $config !== null && empty($config['editable']);
    }

    /** Why a json_config.autonumber can't be used, or null when it's fine. */
    public static function problem(mixed $config): ?string
    {
        if ($config === null) {
            return null;
        }
        if (! is_array($config) || ! is_string($config['format'] ?? null)) {
            return 'An autonumber needs a format, like "PO-{yy}{MM}{dd}{seq:3}".';
        }
        $formats = [$config['format']];
        foreach ((array) ($config['rules'] ?? []) as $rule) {
            if (! is_array($rule) || ! is_string($rule['field'] ?? null) || ! array_key_exists('value', $rule) || ! is_string($rule['format'] ?? null)) {
                return 'Each autonumber rule needs a field, a value and a format.';
            }
            $formats[] = $rule['format'];
        }
        foreach ($formats as $format) {
            if (preg_match_all('/\{seq(?::\d+)?\}/', $format) !== 1) {
                return "The format \"{$format}\" needs {seq} (or {seq:3} for 3 digits) exactly once.";
            }
        }

        return null;
    }

    /**
     * Fills each blank autonumbered field of a new record. Call it inside the transaction
     * that inserts the record, so the counter stays locked until it is saved.
     *
     * @param  Collection<int, MetaParam>  $params
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fill(string $table, Collection $params, array $data): array
    {
        foreach ($params as $param) {
            $config = self::config($param);
            if ($config && in_array($data[$param->field_path] ?? null, [null, ''], true)) {
                $data[$param->field_path] = self::next($table, $param->field_path, self::formatFor($config, $data), $data, true);
            }
        }

        return $data;
    }

    /**
     * The numbers a new record would get now, without taking them, by field.
     *
     * @param  Collection<int, MetaParam>  $params
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public static function preview(string $table, Collection $params, array $data): array
    {
        $numbers = [];
        foreach ($params as $param) {
            if ($config = self::config($param)) {
                $numbers[$param->field_path] = self::next($table, $param->field_path, self::formatFor($config, $data), $data, false);
            }
        }

        return $numbers;
    }

    /** The format for this record: the first matching rule's, else the default. */
    public static function formatFor(array $config, array $data): string
    {
        foreach ((array) ($config['rules'] ?? []) as $rule) {
            $value = $data[$rule['field'] ?? ''] ?? null;
            $wanted = array_map('strval', (array) ($rule['value'] ?? []));
            if ($value !== null && in_array((string) $value, $wanted, true)) {
                return $rule['format'];
            }
        }

        return $config['format'];
    }

    /**
     * The next number in this format. $take moves the counter on (and locks it until the
     * surrounding transaction ends); otherwise it only says what the number would be.
     */
    public static function next(string $table, string $column, string $format, array $data, bool $take, ?CarbonInterface $at = null): string
    {
        [$prefix, $suffix, $digits] = self::split($format, $data, $at ?? now());
        $key = ['table_name' => $table, 'column_name' => $column, 'prefix' => $prefix, 'suffix' => $suffix];

        $number = DB::transaction(function () use ($table, $column, $prefix, $suffix, $key, $take) {
            $counter = DB::table('autonumbers')->where($key);
            if ($take) {
                DB::table('autonumbers')->insertOrIgnore($key + ['last_value' => 0, 'created_at' => now(), 'updated_at' => now()]);
                $counter->lockForUpdate();
            }
            $last = (int) ($counter->value('last_value') ?? 0);
            $number = max($last, self::highestUsed($table, $column, $prefix, $suffix)) + 1;
            if ($take) {
                DB::table('autonumbers')->where($key)->update(['last_value' => $number, 'updated_at' => now()]);
            }

            return $number;
        });

        return $prefix.str_pad((string) $number, $digits, '0', STR_PAD_LEFT).$suffix;
    }

    /**
     * The format's text before and after {seq}, and its digits.
     *
     * @return array{0: string, 1: string, 2: int}
     */
    private static function split(string $format, array $data, CarbonInterface $at): array
    {
        $digits = 1;
        $parts = ['', ''];
        $side = 0;
        $offset = 0;
        preg_match_all(self::TOKEN, $format, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $m) {
            $parts[$side] .= substr($format, $offset, $m[0][1] - $offset);
            $offset = $m[0][1] + strlen($m[0][0]);
            $name = $m[1][0];
            if ($name === 'seq') {
                $digits = max(1, (int) ($m[2][0] ?? 1));
                $side = 1;

                continue;
            }
            $parts[$side] .= match ($name) {
                'yyyy' => $at->format('Y'),
                'yy' => $at->format('y'),
                'MM' => $at->format('m'),
                'dd' => $at->format('d'),
                default => is_scalar($data[$name] ?? null) ? (string) $data[$name] : '',
            };
        }
        $parts[$side] .= substr($format, $offset);

        return [$parts[0], $parts[1], $digits];
    }

    /** The highest number already in the table between this prefix and suffix, e.g. typed or imported ones. */
    private static function highestUsed(string $table, string $column, string $prefix, string $suffix): int
    {
        // LIKE only narrows the rows (a _ in the prefix matches any letter); the pattern decides.
        $like = $prefix.'%'.$suffix;
        $pattern = '/^'.preg_quote($prefix, '/').'(\d+)'.preg_quote($suffix, '/').'$/';
        $wrapped = DB::getQueryGrammar()->wrap($column);

        // The longest values sort first, so the highest number is among the first few.
        return DB::table($table)->where($column, 'like', $like)
            ->orderByRaw("LENGTH({$wrapped}) DESC")->orderByDesc($column)->limit(50)->pluck($column)
            ->map(fn ($v) => preg_match($pattern, (string) $v, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;
    }
}
