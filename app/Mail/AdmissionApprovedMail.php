<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdmissionApprovedMail extends Mailable
{
    use Queueable, SerializesModels;


public function __construct(
    public $studentNumber, 
    public $user // Add this line
) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Admission Approved - FUMCES Portal');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.approved');
    }

 
}