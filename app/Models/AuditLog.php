<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    protected $casts = [
        // Encrypted at rest: a diff's before/after values can be an applicant's name,
        // contact number, address, etc. (same protection as Applicant::casts()).
        'diff' => 'encrypted:array',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('Audit entries cannot be edited.');
        });
        static::deleting(function () {
            throw new \LogicException('Audit entries cannot be deleted.');
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function record(string $action, string $module, string $title, ?string $details = null, ?string $description = null, ?array $diff = null): void
    {
        static::create([
            'user_id' => auth()->id(),
            'actor_name' => auth()->user()?->name,
            'actor_role' => auth()->user()?->roleInfo()[0],
            'action' => $action,
            'module' => $module,
            'title' => $title,
            'details' => $details,
            'description' => $description,
            'diff' => $diff,
            'ip_address' => request()?->ip(),
        ]);
    }
}
