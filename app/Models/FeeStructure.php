<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeeStructure extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'academic_year_id',
        'year_level',
        'base_tuition',
        'total_misc',
        'reg_fee',
        'learning_materials',
        'medical_dental',
        'testing_materials',
        'id_fee',
        'insurance',
        'av_computer',
        'handbook',
        'athletes',
        'red_cross',
        'energy_fee',
        'membership_fees',
        'prisap_umesa',
        'hgp_modules',
        'lab_fees'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'base_tuition' => 'decimal:2',
        'total_misc'   => 'decimal:2',
    ];

  
public function academicYear()
{
    return $this->belongsTo(AcademicYear::class);
}
}