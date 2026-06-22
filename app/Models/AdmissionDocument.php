<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AdmissionDocument extends Model
{
use HasFactory;

    protected $table = 'admissionsdocuments';

   protected $fillable = [
    'report_card', 
    'birth_certificate', 
    'applicant_photo', 
    'father_photo', 
    'mother_photo', 
    'guardian_photo', 
    'transferee_docs'
];

public function user() {
    return $this->belongsTo(User::class);
}
}
