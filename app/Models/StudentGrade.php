<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentGrade extends Model
{
    protected $fillable = ['enrollment_id', 'grading_item_id', 'raw_score'];

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function gradingItem()
    {
        return $this->belongsTo(GradingItem::class);
    }
}