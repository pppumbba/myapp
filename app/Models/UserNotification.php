<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Something a user should know about: type is info, success, alert or danger. */
class UserNotification extends Model
{
    public const TYPES = ['info', 'success', 'alert', 'danger'];

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'type', 'title', 'body', 'link', 'read_at'];

    protected $casts = ['read_at' => 'datetime'];
}
