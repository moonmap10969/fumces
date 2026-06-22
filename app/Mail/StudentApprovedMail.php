<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StudentApprovedMail extends Mailable
{
  public $admission;
public $studentNumber;

public function __construct($admission, $studentNumber)
{
    $this->admission = $admission;
    $this->studentNumber = $studentNumber;
}

public function envelope(): Envelope
{
    return new Envelope(
        subject: 'Welcome to FUMCES - Your Application has been Approved!',
    );
}

public function content(): Content
{
    return new Content(
        view: 'emails.student-approved', // Ensure this file exists in resources/views/emails/
    );
}
}
