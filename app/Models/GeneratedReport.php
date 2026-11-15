<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeneratedReport extends Model
{
    public const UPDATED_AT = null;

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
