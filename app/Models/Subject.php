<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'grade_level',
        'time_period',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Scope to get only active subjects
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Group subjects by grade level for dropdowns
    public static function groupedByLevel()
    {
        return static::active()
            ->orderBy('grade_level')
            ->orderBy('name')
            ->get()
            ->groupBy('grade_level');
    }
}