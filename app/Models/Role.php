<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A role users can have. The "admin" role sees and changes everything. */
class Role extends Model
{
    public const ADMIN = 'admin';

    protected $fillable = ['code', 'name', 'description'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles');
    }
}
