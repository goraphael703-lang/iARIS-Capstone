<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeneratedReport extends Model
{
    public const UPDATED_AT = null;

    // No updated_at at all, and created_at has its own DB default (useCurrent()) - Eloquent's
    // usual auto-timestamping would otherwise silently overwrite an explicitly passed
    // created_at (there's never a reason to pass one outside a test, but it should still work).
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'generated_by_name',
        'template_key',
        'title',
        'recipient',
        'period',
        'school_year',
        'format',
        'snapshot',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            // A saved export's full table data - can include applicant names, contact
            // details, etc. Same protection as Applicant::casts().
            'snapshot' => 'encrypted:array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
