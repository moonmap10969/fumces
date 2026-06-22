<?php

namespace App\Mail;

use App\Models\Admission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ApplicationSubmitted extends Mailable
{
    use Queueable, SerializesModels;

public $admission;

public function __construct($admission)
{
    $this->admission = $admission;
}

public function build()
{
    return $this->view('emails.application_submitted')
                ->subject('FUMCES Application Received');
}
}
