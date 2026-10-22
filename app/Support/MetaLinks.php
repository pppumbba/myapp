<?php

namespace App\Support;

use App\Models\Meta;
use App\Models\MetaMetaMap;
use App\Models\MetaNotificationMap;
use App\Models\MetaParam;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Links between metas, kept in meta_meta_map (and what a notification watches in
 * meta_notification_map) instead of inside json_config. Their foreign keys mean a link to
 * a deleted meta goes with it, and "where is this meta used" is one query.
 *
 * The API still shows and takes links inside json_config, in the shapes the screens use:
 * the MetaConfig cast splits them out when json_config is set and puts them back when it
 * is read. Setting json_config replaces the links it holds; changing other attributes
 * leaves them alone.
 *
 *   a meta's json_config                       a param's json_config
 *   drilldown {meta_id, params, label}         drilldown (a column's)
 *   rows.meta_id, columns.meta_id (matrix)     params {target: source} (a subgrid's)
 *   link_meta_id (notification)                scope {field: record field} (a section's)
 *   print_meta_id (a form's Save and print)
 *   source_meta_id or workflow_meta_id,        foreign_key (a detail's line column)
 *   status_id (notification)                   label_field (old name of display_field)
 *
 * A map row's src is the meta (and param) holding the setting, trg the meta it points at,
 * and src_path / trg_path a pair of fields matched up: the clicked row's field and the
 * target's parameter, the record's field and the section's, the header's key and the
 * line's foreign key. A param's subgrid, scope and detail rows point at its ref_meta_id.
 */
class MetaLinks
{
    /** Link types a meta's own json_config holds. */
    public const META_TYPES = ['drilldown', 'matrix_rows', 'matrix_columns', 'link', 'print'];

    /** Link types a param's json_config holds. */
    public const PARAM_TYPES = ['drilldown', 'subgrid', 'scope', 'detail'];

    /** Param link types whose target is the param's ref_meta_id. */
    public const REF_TYPES = ['subgrid', 'scope', 'detail'];

    /**
     * Splits the links out of a json_config.
     *
     * @return array{0: array<string, mixed>, 1: list<array{type: string, trg_meta_id: ?int, src_path: ?string, trg_path: ?string}>, 2: ?array{meta_id: ?int, status_id: ?int}, 3: array<string, mixed>}
     *                                                                                                                                                                                                   the rest of the config, the links, what a notification watches (null: not set), other attributes to set
     */
    public static function split(array $config, bool $forParam): array
    {
        $links = [];
        $notification = null;
        $attributes = [];

        $drill = $config['drilldown'] ?? null;
        if (is_array($drill) && is_numeric($drill['meta_id'] ?? null)) {
            array_push($links, ...self::pairs('drilldown', (int) $drill['meta_id'], (array) ($drill['params'] ?? [])));
            $rest = array_diff_key($drill, ['meta_id' => 1, 'params' => 1]);
            if ($rest) {
                $config['drilldown'] = $rest;
            } else {
                unset($config['drilldown']);
            }
        }

        if ($forParam) {
            foreach (['params' => 'subgrid', 'scope' => 'scope'] as $key => $type) {
                if (is_array($config[$key] ?? null)) {
                    array_push($links, ...self::pairs($type, null, $config[$key]));
                    unset($config[$key]);
                }
            }
            if (is_string($config['foreign_key'] ?? null) && $config['foreign_key'] !== '') {
                $links[] = ['type' => 'detail', 'trg_meta_id' => null, 'src_path' => 'id', 'trg_path' => $config['foreign_key']];
            }
            unset($config['foreign_key']);
            if (is_string($config['label_field'] ?? null) && $config['label_field'] !== '') {
                $attributes['display_field'] = $config['label_field'];
            }
            unset($config['label_field']);
        } else {
            foreach (['rows' => 'matrix_rows', 'columns' => 'matrix_columns'] as $key => $type) {
                if (is_array($config[$key] ?? null) && is_numeric($config[$key]['meta_id'] ?? null)) {
                    $links[] = ['type' => $type, 'trg_meta_id' => (int) $config[$key]['meta_id'], 'src_path' => null, 'trg_path' => null];
                    unset($config[$key]['meta_id']);
                }
            }
            foreach (['link_meta_id' => 'link', 'print_meta_id' => 'print'] as $key => $type) {
                if (is_numeric($config[$key] ?? null)) {
                    $links[] = ['type' => $type, 'trg_meta_id' => (int) $config[$key], 'src_path' => null, 'trg_path' => null];
                }
                unset($config[$key]);
            }

            if (array_intersect_key($config, ['source_meta_id' => 1, 'workflow_meta_id' => 1, 'status_id' => 1])) {
                $watched = ($config['event'] ?? null) === 'status_changed' ? ($config['workflow_meta_id'] ?? null) : ($config['source_meta_id'] ?? null);
                $notification = [
                    'meta_id' => is_numeric($watched) ? (int) $watched : null,
                    'status_id' => is_numeric($config['status_id'] ?? null) ? (int) $config['status_id'] : null,
                ];
                unset($config['source_meta_id'], $config['workflow_meta_id'], $config['status_id']);
            }
        }

        return [$config, $links, $notification, $attributes];
    }

    /**
     * Puts the links back into a json_config, in the shapes split() takes.
     *
     * @param  Collection<int, MetaMetaMap>  $links
     */
    public static function merge(array $config, Collection $links, ?MetaNotificationMap $notification, bool $forParam): array
    {
        $byType = $links->groupBy('type');
        $pairs = fn (Collection $rows) => $rows->filter(fn ($r) => $r->trg_path !== null && $r->trg_path !== '')
            ->mapWithKeys(fn ($r) => [$r->trg_path => (string) $r->src_path])->all();

        if ($drill = $byType->get('drilldown')) {
            $config['drilldown'] = ['meta_id' => $drill->first()->trg_meta_id, 'params' => (object) $pairs($drill)] + (array) ($config['drilldown'] ?? []);
        }

        if ($forParam) {
            if ($rows = $byType->get('subgrid')) {
                $config['params'] = (object) $pairs($rows);
            }
            if ($rows = $byType->get('scope')) {
                $config['scope'] = (object) $pairs($rows);
            }
            if ($row = $byType->get('detail')?->first()) {
                $config['foreign_key'] = $row->trg_path;
            }
        } else {
            foreach (['matrix_rows' => 'rows', 'matrix_columns' => 'columns'] as $type => $key) {
                if ($row = $byType->get($type)?->first()) {
                    $config[$key] = ['meta_id' => $row->trg_meta_id] + (array) ($config[$key] ?? []);
                }
            }
            foreach (['link' => 'link_meta_id', 'print' => 'print_meta_id'] as $type => $key) {
                if ($row = $byType->get($type)?->first()) {
                    $config[$key] = $row->trg_meta_id;
                }
            }
            if ($notification) {
                $key = ($config['event'] ?? null) === 'status_changed' ? 'workflow_meta_id' : 'source_meta_id';
                $config[$key] = $notification->meta_id;
                $config['status_id'] = $notification->status_id;
            }
        }

        return $config;
    }

    /**
     * Saves a meta's or param's links as split() found them: its rows of those types are
     * replaced, and a notification's watched meta and status set. Links to metas that no
     * longer exist are dropped.
     *
     * @param  list<array{type: string, trg_meta_id: ?int, src_path: ?string, trg_path: ?string}>  $links
     */
    public static function save(Meta|MetaParam $owner, array $links, ?array $notification): void
    {
        $forParam = $owner instanceof MetaParam;
        DB::transaction(function () use ($owner, $links, $notification, $forParam) {
            $existing = $forParam
                ? MetaMetaMap::where('src_param_id', $owner->id)->whereIn('type', self::PARAM_TYPES)
                : MetaMetaMap::where('src_meta_id', $owner->id)->whereNull('src_param_id')->whereIn('type', self::META_TYPES);
            $existing->delete();

            foreach ($links as $link) {
                $target = $link['trg_meta_id'] ?? ($forParam ? $owner->ref_meta_id : null);
                if (! $target || ! Meta::whereKey($target)->exists()) {
                    continue;
                }
                MetaMetaMap::create([
                    'src_meta_id' => $forParam ? $owner->main_meta_id : $owner->id,
                    'src_param_id' => $forParam ? $owner->id : null,
                    'trg_meta_id' => $target,
                    'src_path' => $link['src_path'],
                    'trg_path' => $link['trg_path'],
                    'type' => $link['type'],
                ]);
            }

            if (! $forParam && $notification !== null) {
                MetaNotificationMap::updateOrCreate(['notification_id' => $owner->id], [
                    'meta_id' => $notification['meta_id'] && Meta::whereKey($notification['meta_id'])->exists() ? $notification['meta_id'] : null,
                    'status_id' => $notification['status_id'] && DB::table('wfm_status')->where('id', $notification['status_id'])->exists() ? $notification['status_id'] : null,
                ]);
            }
        });
    }

    /** A param's subgrid, scope and detail links follow its ref_meta_id; without one they go. */
    public static function retarget(MetaParam $param): void
    {
        $rows = MetaMetaMap::where('src_param_id', $param->id)->whereIn('type', self::REF_TYPES);
        if ($param->ref_meta_id) {
            $rows->update(['trg_meta_id' => $param->ref_meta_id]);
        } else {
            $rows->delete();
        }
    }

    /**
     * One link per pair {target field: source field}; none gives one row without fields.
     *
     * @return list<array{type: string, trg_meta_id: ?int, src_path: ?string, trg_path: ?string}>
     */
    private static function pairs(string $type, ?int $target, array $map): array
    {
        $rows = [];
        foreach ($map as $trg => $src) {
            if (is_string($trg) && $trg !== '' && is_scalar($src)) {
                $rows[] = ['type' => $type, 'trg_meta_id' => $target, 'src_path' => (string) $src, 'trg_path' => $trg];
            }
        }

        return $rows ?: [['type' => $type, 'trg_meta_id' => $target, 'src_path' => null, 'trg_path' => null]];
    }
}
