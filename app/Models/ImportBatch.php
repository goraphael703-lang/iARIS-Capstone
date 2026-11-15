<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'uploaded_by',
        'source_hash',
        'original_filename',
        'source',
        'row_count',
        'error_count',
        'processing_ms',
        'errors',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
        ];
    }

    // The applicants this upload saved
    public function applicants(): HasMany
    {
        return $this->hasMany(Applicant::class, 'imported_batch_id');
    }
}
