<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row of a meta's structure. Its meaning depends on the owner's type:
 *  - owner app   -> menu item (ref_meta_id = the screen it opens)
 *  - owner datagrid -> column (ref_meta_id = lookup meta, if any)
 *  - owner form  -> field     (ref_meta_id = lookup/related meta)
 *  - owner tabs  -> tab       (ref_meta_id = the meta the tab shows)
 * parent_id nests rows inside each other at any level.
 */
class MetaParam extends Model
{
    protected $table = 'meta_params';

    protected $fillable = [
        'order_number',
        'parent_id',
        'field_path',
        'param_name',
        'column_path',
        'label_name',
        'data_type',
        'data_mask',
        'render_type',
        'icon_cls',
        'main_meta_id',
        'ref_meta_id',
        'is_primary',
        'is_show',
        'is_required',
        'is_criteria',
        'is_unique',
        'is_merge',
        'is_select',
        'is_mandatory_criteria',
        'default_value',
        'min_value',
        'max_value',
        'column_width',
        'expression_string',
        'json_config',
    ];

    protected $casts = [
        'order_number' => 'integer',
        'is_primary' => 'boolean',
        'is_show' => 'boolean',
        'is_required' => 'boolean',
        'is_criteria' => 'boolean',
        'is_unique' => 'boolean',
        'is_merge' => 'boolean',
        'is_select' => 'boolean',
        'is_mandatory_criteria' => 'boolean',
        'json_config' => 'array',
    ];

    /** The meta (app, datagrid, form, ...) this row belongs to. */
    public function main(): BelongsTo
    {
        return $this->belongsTo(Meta::class, 'main_meta_id');
    }

    /** The meta this row opens or links to. */
    public function refMeta(): BelongsTo
    {
        return $this->belongsTo(Meta::class, 'ref_meta_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MetaParam::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(MetaParam::class, 'parent_id')->orderBy('order_number');
    }
}
