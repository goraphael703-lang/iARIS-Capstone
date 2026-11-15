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

    // Singleton: one policy row for the whole system.
    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1], [
            'enrolled_after_years' => 3,
            'not_enrolled_after_years' => 2,
            'delete_after_years' => 2,
        ]);
    }
}
