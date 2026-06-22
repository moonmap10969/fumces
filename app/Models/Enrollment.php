<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Enrollment extends Model
{
    use HasFactory;

    protected $fillable = [
        'studentNumber', 
        'academic_year_id',
        'section_id', 
        // 'shift', 
        'year_level', 
        'school_year', 
        'status'
    ];

   
public function tuition()
{
    return $this->hasOne(Tuition::class, 'enrollment_id', 'id');
}

    public function admission()
    {
        return $this->belongsTo(Admission::class, 'studentNumber', 'studentNumber');
    }

 
    public function section()
    {
        return $this->belongsTo(Section::class, 'section_id', 'section_id');
    }

    public function classRecord()
    {
        return $this->hasOne(ClassRecord::class, 'studentNumber', 'id');
    }

    public function grades()
    {
        return $this->hasMany(StudentGrade::class, 'enrollment_id', 'id');
    }
}