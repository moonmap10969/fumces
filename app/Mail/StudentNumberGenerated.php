<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class StudentNumberGenerated extends Mailable
{
    use Queueable, SerializesModels;

    public $studentNumber;

    public function __construct($studentNumber)
    {
        $this->studentNumber = $studentNumber;
    }

    public function build()
    {
        return $this->subject('Your Student Admission Details')
                    ->view('emails.student_approved');
    }
}