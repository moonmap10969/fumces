<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuarterActivation extends Model
{
    protected $fillable = [
        'academic_year_id',
        'quarter',
        'is_active',
    ];

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id', 'id');
    }
}

