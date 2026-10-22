<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved version of a meta (see App\Support\MetaVersions). snapshot holds the meta's row
 * and its parts' rows as they were; it is left out of lists, being large.
 */
class MetaVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['meta_id', 'version_no', 'code', 'name', 'note', 'snapshot', 'hash', 'created_by'];

    protected $casts = [
        'snapshot' => 'array',
        'version_no' => 'integer',
        'meta_id' => 'integer',
    ];

    protected $hidden = ['snapshot'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
