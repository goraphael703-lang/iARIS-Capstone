<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArchiveSetting extends Model
{
    protected $fillable = [
        'enrolled_after_years',
        'not_enrolled_after_years',
        'delete_after_years',
        'updated_by',
    ];

    // Singleton: one policy row for the whole system. Defaults match DLSL's stated policy
    // (no retention past one school year) - see the 2026_11_16_100000 migration.
    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1], [
            'enrolled_after_years' => 1,
            'not_enrolled_after_years' => 1,
            'delete_after_years' => 2,
        ]);
    }
}
