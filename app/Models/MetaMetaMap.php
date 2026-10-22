<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A link from one meta (src, and the param holding it) to another (trg), of a type:
 * drilldown, subgrid, scope, detail, matrix_rows, matrix_columns or link. src_path and
 * trg_path are a pair of fields it matches up. See App\Support\MetaLinks.
 */
class MetaMetaMap extends Model
{
    protected $table = 'meta_meta_map';

    protected $fillable = ['src_meta_id', 'trg_meta_id', 'src_path', 'trg_path', 'src_param_id', 'trg_param_id', 'type'];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Meta::class, 'src_meta_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Meta::class, 'trg_meta_id');
    }

    public function sourceParam(): BelongsTo
    {
        return $this->belongsTo(MetaParam::class, 'src_param_id');
    }
}
