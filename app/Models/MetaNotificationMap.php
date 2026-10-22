<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a notification meta watches: the meta whose records it is about (for a status
 * change, the workflow) and, optionally, the status records move into.
 */
class MetaNotificationMap extends Model
{
    protected $table = 'meta_notification_map';

    protected $fillable = ['notification_id', 'meta_id', 'status_id'];

    protected $casts = ['notification_id' => 'integer', 'meta_id' => 'integer', 'status_id' => 'integer'];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Meta::class, 'notification_id');
    }

    public function meta(): BelongsTo
    {
        return $this->belongsTo(Meta::class, 'meta_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(WfmStatus::class, 'status_id');
    }
}
