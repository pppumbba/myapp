<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What one role may do with one workflow status: move records into it (can_enter) and see
 * records while they're in it (can_see). Without rows of a kind, that is open to everyone.
 */
class WfmStatusPermission extends Model
{
    public $timestamps = false;

    protected $fillable = ['status_id', 'role_id', 'can_enter', 'can_see'];

    protected $casts = ['can_enter' => 'boolean', 'can_see' => 'boolean'];
}
