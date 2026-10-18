<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MetaType extends Model
{
    protected $table = 'meta_types';

    protected $fillable = ['code', 'name', 'is_active', 'description'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function metas(): HasMany
    {
        return $this->hasMany(Meta::class, 'type_id');
    }
}