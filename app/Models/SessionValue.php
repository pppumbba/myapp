<?php

namespace App\Models;

use App\Support\SessionValues;
use Illuminate\Database\Eloquent\Model;

/**
 * A custom session value: path (e.g. sessionemployeeid) is worked out per user by query,
 * with param (e.g. userid) bound to it. See SessionValues.
 */
class SessionValue extends Model
{
    protected $fillable = ['path', 'query', 'param'];

    protected static function booted(): void
    {
        // Every user's cached values are worked out again after a definition changes.
        static::saved(fn () => SessionValues::forgetAll());
        static::deleted(fn () => SessionValues::forgetAll());
    }
}
