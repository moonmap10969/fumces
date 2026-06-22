<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tuition extends Model
{
    use HasFactory;

     protected $table = 'tuitions';

    protected $fillable = [
        'enrollment_id',
        'studentNumber',
        'academic_year_id',
        'name',
        'year_level',
        'tuition_fee',
        'misc_fees',
        'amount',
        'paid_amount',
        'balance',
        'reference_number',
        'status',
        'payment_schedule',
        'umc_affiliation',
        'sibling_order',
        'grade_level',
        'payment_method',
        'approval_status',
        'payment_type',
        'payment_proof'
    ];

    protected static function booted()
    {
        static::creating(function ($tuition) {
            if (empty($tuition->balance)) {
                $tuition->balance = $tuition->amount;
            }
        });
    }

// ONLY ONE INSTANCE OF THIS FUNCTION
    public function recalcTotals(): void
    {
        $assessment = (float) ($this->amount ?? 0);
        if ($assessment <= 0) {
            $assessment = (float) ($this->tuition_fee ?? 0) + (float) ($this->misc_fees ?? 0);
        }

        $paid = (float) $this->payments()
            ->whereIn('status', ['completed', 'approved'])
            ->sum('amount');

        $this->paid_amount = $paid;
        $this->balance = max(0, $assessment - $paid);

        if ($this->balance <= 0 && $assessment > 0) {
            $this->status = 'paid';
        } elseif ($this->paid_amount > 0) {
            $this->status = 'partial';
        } else {
            $this->status = 'pending';
        }

        $this->save();
    }
    public function admission()
    {
        return $this->belongsTo(Admission::class, 'studentNumber', 'studentNumber');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'tuition_id', 'id');
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id', 'id');
    }
    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id', 'id');
    }
}