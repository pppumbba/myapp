<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Runs the target step after the source step (none: the main form) when its criteria hold. */
class MetaProcessTransition extends Model
{
    protected $table = 'meta_process_transition';

    protected $fillable = ['main_process_id', 'source_id', 'target_id', 'criteria'];

    protected $casts = ['criteria' => 'array'];
}
