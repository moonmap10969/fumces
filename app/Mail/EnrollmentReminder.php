<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EnrollmentReminder extends Mailable
{
    use Queueable, SerializesModels;

    public $studentName;
    public $customMessage;

    public function __construct($studentName, $customMessage)
    {
        $this->studentName = $studentName;
        $this->customMessage = $customMessage;
    }

    public function build()
    {
        return $this->subject('Action Required: Enrollment for SY 2026-2027')
                    ->view('emails.enrollment_reminder');
    }
}
