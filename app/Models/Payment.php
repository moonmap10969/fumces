<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

protected $fillable = [
    'enrollment_id',
    'tuition_id',
    'studentNumber',
    'academic_year_id',
    'amount',
    'payment_method',
    'reference_number',
    'receipt_path',
    'status',
    'origin',
    'approval_status',
    'remarks',
];

    protected $attributes = [
    'status'          => 'pending',      
    'approval_status' => 'pending',      
    ];

    /**
     * Relationship: Payment belongs to Tuition
     */
public function tuition()
{
    return $this->belongsTo(Tuition::class);
}

public function enrollment()
{
    return $this->belongsTo(Enrollment::class, 'enrollment_id');
}

public function admission()
{
    return $this->belongsTo(Admission::class, 'studentNumber', 'studentNumber');
}

public function approvedPayments()
{
    return $this->hasMany(Payment::class, 'studentNumber', 'studentNumber')
                ->where('approval_status', 'approved');
}

}