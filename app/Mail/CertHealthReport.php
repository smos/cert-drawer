<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CertHealthReport extends Mailable
{
    use Queueable, SerializesModels;

    public array $changes;

    public function __construct(array $changes)
    {
        $this->changes = $changes;
    }

    public function build()
    {
        return $this->subject('Certificate Health Alert - ' . config('app.name'))
                    ->view('emails.cert_health');
    }
}
