<?php

use App\Models\MetaNotificationMap;
use App\Support\MetaLinks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Links between metas move out of json_config into tables with foreign keys (see
 * App\Support\MetaLinks), so a link to a deleted meta goes with it:
 *
 * - meta_meta_map: drilldowns, subgrid and workspace-section mappings, a detail's line
 *   foreign key (header -> line form), matrix rows and columns, a notification's link.
 * - meta_notification_map: the meta (or workflow) and status a notification watches.
 * - meta_params.display_field / value_field: what a lookup shows and stores (display_field
 *   was json_config.label_field); is_label marks the field naming a meta's records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_meta_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('src_meta_id')->constrained('metas')->cascadeOnDelete();
            $table->foreignId('trg_meta_id')->constrained('metas')->cascadeOnDelete();
            $table->string('src_path')->nullable();
            $table->string('trg_path')->nullable();
            $table->foreignId('src_param_id')->nullable()->constrained('meta_params')->cascadeOnDelete();
            $table->foreignId('trg_param_id')->nullable()->constrained('meta_params')->cascadeOnDelete();
            $table->string('type', 30);
            $table->timestamps();
            $table->index(['src_meta_id', 'src_param_id', 'type']);
        });

        Schema::create('meta_notification_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->unique()->constrained('metas')->cascadeOnDelete();
            $table->foreignId('meta_id')->nullable()->constrained('metas')->nullOnDelete();
            $table->foreignId('status_id')->nullable()->constrained('wfm_status')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('meta_params', function (Blueprint $table) {
            $table->string('display_field')->nullable()->after('ref_meta_id');
            $table->string('value_field')->nullable()->after('display_field');
            $table->boolean('is_label')->default(false)->after('is_primary');
        });

        $this->moveOutOfJson();
    }

    public function down(): void
    {
        $this->moveBackIntoJson();

        Schema::table('meta_params', function (Blueprint $table) {
            $table->dropColumn(['display_field', 'value_field', 'is_label']);
        });
        Schema::dropIfExists('meta_notification_map');
        Schema::dropIfExists('meta_meta_map');
    }

    private function moveOutOfJson(): void
    {
        $metaIds = DB::table('metas')->pluck('id')->flip();
        $now = now();
        $link = fn (array $l, int $src, ?int $param, ?int $target) => [
            'src_meta_id' => $src, 'src_param_id' => $param, 'trg_meta_id' => $target, 'trg_param_id' => null,
            'src_path' => $l['src_path'], 'trg_path' => $l['trg_path'], 'type' => $l['type'], 'created_at' => $now, 'updated_at' => $now,
        ];

        foreach (DB::table('metas')->whereNotNull('json_config')->get(['id', 'json_config']) as $meta) {
            [$rest, $links, $notification] = MetaLinks::split((array) json_decode($meta->json_config, true), false);
            foreach ($links as $l) {
                if (isset($metaIds[$l['trg_meta_id']])) {
                    DB::table('meta_meta_map')->insert($link($l, $meta->id, null, $l['trg_meta_id']));
                }
            }
            if ($notification !== null) {
                DB::table('meta_notification_map')->insert([
                    'notification_id' => $meta->id,
                    'meta_id' => isset($metaIds[$notification['meta_id']]) ? $notification['meta_id'] : null,
                    'status_id' => $notification['status_id'] && DB::table('wfm_status')->where('id', $notification['status_id'])->exists() ? $notification['status_id'] : null,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            DB::table('metas')->where('id', $meta->id)->update(['json_config' => $rest ? json_encode($rest) : null]);
        }

        foreach (DB::table('meta_params')->whereNotNull('json_config')->get(['id', 'main_meta_id', 'ref_meta_id', 'json_config']) as $param) {
            [$rest, $links, , $attributes] = MetaLinks::split((array) json_decode($param->json_config, true), true);
            foreach ($links as $l) {
                $target = $l['trg_meta_id'] ?? $param->ref_meta_id;
                if (isset($metaIds[$target])) {
                    DB::table('meta_meta_map')->insert($link($l, $param->main_meta_id, $param->id, $target));
                }
            }
            DB::table('meta_params')->where('id', $param->id)->update(['json_config' => $rest ? json_encode($rest) : null] + $attributes);

            // The field a lookup showed now names its meta's records, unless one already does.
            if (isset($attributes['display_field']) && $param->ref_meta_id
                && ! DB::table('meta_params')->where('main_meta_id', $param->ref_meta_id)->where('is_label', true)->exists()) {
                DB::table('meta_params')->where('main_meta_id', $param->ref_meta_id)->where('field_path', $attributes['display_field'])->limit(1)->update(['is_label' => true]);
            }
        }
    }

    private function moveBackIntoJson(): void
    {
        $merge = function (string $table, object $row, bool $forParam) {
            $links = DB::table('meta_meta_map')->where($forParam ? 'src_param_id' : 'src_meta_id', $row->id)
                ->when(! $forParam, fn ($q) => $q->whereNull('src_param_id'))->orderBy('id')->get();
            $notification = $forParam ? null : DB::table('meta_notification_map')->where('notification_id', $row->id)->first();
            $config = (array) json_decode($row->json_config ?? 'null', true);
            if ($links->isNotEmpty() || $notification) {
                $config = MetaLinks::merge($config, $links, $notification ? new MetaNotificationMap((array) $notification) : null, $forParam);
            }
            if ($forParam && $row->display_field) {
                $config['label_field'] = $row->display_field;
            }
            DB::table($table)->where('id', $row->id)->update(['json_config' => $config ? json_encode($config) : null]);
        };

        foreach (DB::table('metas')->get(['id', 'json_config']) as $meta) {
            $merge('metas', $meta, false);
        }
        foreach (DB::table('meta_params')->get(['id', 'json_config', 'display_field']) as $param) {
            $merge('meta_params', $param, true);
        }
    }
};
