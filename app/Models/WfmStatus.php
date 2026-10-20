<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One status of a workflow. meta_id is the form opened for a record moving into it. */
class WfmStatus extends Model
{
    protected $table = 'wfm_status';

    public $timestamps = false;

    protected $fillable = ['code', 'name', 'workflow_meta_id', 'color', 'meta_id', 'label_name'];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Meta::class, 'workflow_meta_id');
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Meta::class, 'meta_id');
    }
}
