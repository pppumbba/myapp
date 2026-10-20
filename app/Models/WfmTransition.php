<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A move from one status to another (from none, when prev_status_id is empty). It is
 * offered only when criteria holds for the record, and only in source_id's grid when set.
 * prev_id/next_id link transitions in the diagram; is_transition false draws it unusable.
 */
class WfmTransition extends Model
{
    protected $table = 'wfm_transition';

    public $timestamps = false;

    protected $fillable = ['prev_status_id', 'next_status_id', 'criteria', 'prev_id', 'next_id', 'source_id', 'is_transition', 'workflow_meta_id'];

    protected $casts = ['is_transition' => 'boolean'];

    public function prevStatus(): BelongsTo
    {
        return $this->belongsTo(WfmStatus::class, 'prev_status_id');
    }

    public function nextStatus(): BelongsTo
    {
        return $this->belongsTo(WfmStatus::class, 'next_status_id');
    }
}
