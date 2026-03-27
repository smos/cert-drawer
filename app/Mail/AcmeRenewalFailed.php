<?php

namespace App\Mail;

use App\Models\Certificate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AcmeRenewalFailed extends Mailable
{
    use Queueable, SerializesModels;

    public Certificate $certificate;
    public string $errorMessage;

    public function __construct(Certificate $certificate, string $errorMessage)
    {
        $this->certificate = $certificate;
        $this->errorMessage = $errorMessage;
    }

    public function build()
    {
        return $this->subject('ACME Renewal FAILED - ' . $this->certificate->domain->name)
                    ->view('emails.acme_renewal_failed');
    }
}
