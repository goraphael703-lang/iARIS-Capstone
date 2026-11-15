<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewQueueItem extends Model
{
    protected $table = 'review_queue';

    public function getRouteKeyName(): string
    {
        return 'rq_number';
    }

    protected $fillable = [
        'rq_number',
        'type',
        'title',
        'applicant_id',
        'subject',
        'ref',
        'unit',
        'submitted_by',
        'submitted_by_name',
        'submitted_by_role',
        'submitted_at',
        'status',
        'note',
        'changes',
        'pending_action',
        'import_summary',
        'import_sample',
        'import_errors',
        'reviewed_by',
        'reviewed_at',
        'review_reason',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            // The applicant's name, shown as the card title while this item is waiting.
            'subject' => 'encrypted',
            // Encrypted at rest: these hold proposed applicant names/contact details before
            // they're approved, so they need the same protection as the applicants table
            // itself (see Applicant::casts()). Same caveat applies: nothing can query inside
            // these columns with raw SQL - PHP already only ever reads them decrypted.
            'changes' => 'encrypted:array',
            'pending_action' => 'encrypted:array',
            'import_summary' => 'encrypted:array',
            'import_sample' => 'encrypted:array',
            'import_errors' => 'encrypted:array',
        ];
    }

    /** How many are waiting (for the sidebar badge). */
    public static function waitingCount(): int
    {
        return self::where('status', 'waiting')->count();
    }

    public static function nextRqNumber(): string
    {
        return 'RQ-'.strtoupper(bin2hex(random_bytes(8)));
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
