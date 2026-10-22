<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One form (or stage query) a schedule runs: process_id is it, view_id the datagrid whose rows it runs over. */
class MetaSchedule extends Model
{
    protected $fillable = ['schedule_id', 'process_id', 'view_id'];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(Meta::class, 'process_id');
    }

    public function view(): BelongsTo
    {
        return $this->belongsTo(Meta::class, 'view_id');
    }
}
