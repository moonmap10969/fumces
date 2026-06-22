<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SmsLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'studentNumber',
        'phone_number',
        'message',
        'status',
    ];

    /**
     * Relationship to the Student (Admission)
     */
    public function student()
    {
        return $this->belongsTo(Admission::class, 'studentNumber', 'studentNumber');
    }
}