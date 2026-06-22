<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
 
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'subject',
        'teacher',
        'day_of_week',
        'start_time',
        'end_time',
        'room',
        'year_level',
        'section',
        'section_id', 
    ];
}