<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One value a step's form takes: target_path from source_path, which is a field of the
 * record just saved, "main.field" of the main form's record, or "=text" a fixed value.
 */
class MetaProcessBehaviour extends Model
{
    protected $table = 'meta_process_behaviour';

    protected $fillable = ['workflow_id', 'source_path', 'target_path'];
}
