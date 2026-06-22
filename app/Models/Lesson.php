<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lesson extends Model
{
    protected $fillable = [
        'lesson_plan_ref',
        'course_ref',
        'title',
        'subject',
        'topic',
        'date',
        'grade_level',
        'lesson_duration',
        'description',        // Learning Objectives
        'summary_of_tasks',
        'materials_equipment',
        'references',
        'take_home_tasks',
        'status',
        'file_path',
    ];

    protected $casts = [
        'date' => 'date',
    ];
}