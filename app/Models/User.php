<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role', 'studentNumber', 'year_level', 'is_approved',
    ];

    protected $hidden = ['password', 'remember_token'];
    
    public function sendEmailVerificationNotification()
    {
        $this->notify(new \App\Notifications\CustomVerifyEmail);
    }

    public function isRegistrar(): bool
    {
        return $this->role === 'registrar';
    }

    public function documents(): HasMany {
        return $this->hasMany(Document::class, 'user_id');
    }

    public function admission(): HasOne
    {
        return $this->hasOne(Admission::class, 'user_id', 'id');
    }

    public function payments(): HasMany 
    {
        return $this->hasMany(Payment::class);
    }
    public function enrollment(): HasOne
{
    // This tells Laravel: "Find my admission, then find the enrollment linked to that admission's studentNumber"
    return $this->hasOneThrough(
        Enrollment::class,
        Admission::class,
        'user_id',       // Foreign key on admissions table
        'studentNumber', // Foreign key on enrollments table
        'id',            // Local key on users table
        'studentNumber'  // Local key on admissions table
    );
}
}