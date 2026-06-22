<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Section extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'section_id';
    
    public $incrementing = false;      
    protected $keyType = 'int';        

    protected $fillable = [
        'section_id',
        'name',
        'capacity',
        'shift',
        'year_level',
    ];


    /**
     * Relationship to Enrollments.
     */
    public function enrollments()
    {
        // format: hasMany(RelatedModel, foreign_key_on_enrollments, local_key_on_sections)
        return $this->hasMany(Enrollment::class, 'section_id', 'section_id');
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class, 'section_id'); 
    }
}