<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A label saying what metas are for ("Procurement", "Finance", "Report"). A meta can have
 * several, through meta_tag_map. Anyone signed in adds tags; admins rename and remove them.
 */
class Tag extends Model
{
    /** The colors a tag may have; the screens draw each as a badge color. */
    public const COLORS = ['gray', 'brand', 'blue', 'indigo', 'purple', 'pink', 'orange', 'warning', 'success', 'error'];

    protected $fillable = ['name', 'color', 'created_by'];

    protected $hidden = ['pivot'];

    public function metas(): BelongsToMany
    {
        return $this->belongsToMany(Meta::class, 'meta_tag_map')->withTimestamps();
    }
}
