<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** What one role may do with one meta. A meta without any rows is open to everyone. */
class MetaPermission extends Model
{
    public const ABILITIES = ['view', 'create', 'edit', 'delete'];

    public $timestamps = false;

    protected $fillable = ['meta_id', 'role_id', 'can_view', 'can_create', 'can_edit', 'can_delete'];

    protected $casts = ['can_view' => 'boolean', 'can_create' => 'boolean', 'can_edit' => 'boolean', 'can_delete' => 'boolean'];
}
