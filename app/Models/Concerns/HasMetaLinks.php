<?php

namespace App\Models\Concerns;

use App\Models\MetaParam;
use App\Support\MetaLinks;
use Illuminate\Database\Eloquent\Model;

/**
 * Saves the links the MetaConfig cast took out of json_config, once the model has an id.
 * A param whose ref_meta_id changes takes its subgrid, scope and detail links with it.
 */
trait HasMetaLinks
{
    /**
     * Links split out of json_config when it was last set, waiting for the save.
     *
     * @var array{0: list<array>, 1: ?array}|null
     */
    public ?array $pendingLinks = null;

    public static function bootHasMetaLinks(): void
    {
        static::saved(function (Model $model) {
            if ($model->pendingLinks !== null) {
                [$links, $notification] = $model->pendingLinks;
                $model->pendingLinks = null;
                MetaLinks::save($model, $links, $notification);
            } elseif ($model instanceof MetaParam && $model->wasChanged('ref_meta_id')) {
                MetaLinks::retarget($model);
            }
            $model->unsetRelation('links');
            if ($model->relationLoaded('notificationLink')) {
                $model->unsetRelation('notificationLink');
            }
        });
    }
}
