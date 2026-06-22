<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademicYear extends Model
{
    protected $fillable = ['year_range', 'is_current'];

    public function admissions() {
        return $this->hasMany(Admission::class);
    }

    public function tuitions() {
        return $this->hasMany(Tuition::class);
    }
}