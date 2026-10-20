<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One move of a record: into status_id from prev_status_id, by created_by, time_spent seconds after the last. */
class WfmStatusLog extends Model
{
    protected $table = 'wfm_status_log';

    public const UPDATED_AT = null;

    protected $fillable = ['workflow_meta_id', 'record_id', 'status_id', 'created_by', 'prev_status_id', 'time_spent'];

    public function status(): BelongsTo
    {
        return $this->belongsTo(WfmStatus::class, 'status_id');
    }

    public function prevStatus(): BelongsTo
    {
        return $this->belongsTo(WfmStatus::class, 'prev_status_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
