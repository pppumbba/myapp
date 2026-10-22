<?php

namespace App\Casts;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Support\MetaLinks;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * json_config of a meta or a param, with its links to other metas living in meta_meta_map
 * (see MetaLinks): read, they are put back into the config; set, they are taken out and
 * saved with the model (HasMetaLinks), and a param's old label_field becomes display_field.
 *
 * @implements CastsAttributes<array|null, array|null>
 */
class MetaConfig implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        /** @var Meta|MetaParam $model */
        $config = is_string($value) ? json_decode($value, true) : null;
        if (! $model->exists) {
            return $config;
        }
        $forParam = $model instanceof MetaParam;
        $links = $model->links;
        $notification = $forParam ? null : $model->notificationLink;
        if ($links->isEmpty() && ! $notification) {
            return $config;
        }

        return MetaLinks::merge($config ?? [], $links, $notification, $forParam);
    }

    /** @return array<string, mixed> */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        /** @var Meta|MetaParam $model */
        [$config, $links, $notification, $extra] = MetaLinks::split(is_array($value) ? $value : [], $model instanceof MetaParam);
        $model->pendingLinks = [$links, $notification];

        // An old label_field sets display_field; one set after json_config still wins.
        return [$key => $config === [] ? null : json_encode($config)] + $extra;
    }
}
