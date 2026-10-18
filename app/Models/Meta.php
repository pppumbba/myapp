<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Meta extends Model
{
    protected $table = 'metas';

    protected $fillable = [
        'code', 'name', 'label_name', 'is_active', 'created_by', 'updated_by',
        'ref_meta_id', 'description', 'table_name', 'query_string',
        'json_config', 'type_id',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'json_config' => 'array',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(MetaType::class, 'type_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function refMeta(): BelongsTo
    {
        return $this->belongsTo(Meta::class, 'ref_meta_id');
    }

    public function params(): HasMany
    {
        return $this->hasMany(MetaParam::class, 'main_meta_id')->orderBy('order_number');
    }
}