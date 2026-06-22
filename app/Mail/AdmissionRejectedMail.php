<?php

namespace App\Mail;

// Change this line to your actual model
use App\Models\Admission; 
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdmissionRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    // Update the type hint here too
    public function __construct(public Admission $admission) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Admission Decision Update');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.admission_rejected');
    }
}