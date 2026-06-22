<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ClassRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'studentNumber', 
        'section_id', 
        'quarter',
        'subject',
        'final_average'
    ];

    /**
     * Link back to the Enrollment model.
     * studentNumber in this table is the Foreign Key pointing to Enrollment's ID.
     */
    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class, 'studentNumber', 'id');
    }

    /**
     * Optional: Link directly to the Section.
     */
    public function section()
    {
        return $this->belongsTo(Section::class, 'section_id', 'section_id');
    }
}