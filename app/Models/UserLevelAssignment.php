<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One school level (and, for the Integrated School, one sub-level) a user may see. See App\Support\LevelAssignments.
class UserLevelAssignment extends Model
{
    protected $fillable = ['user_id', 'role', 'level', 'sub_level'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
