<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class DnsHealthReport extends Mailable
{
    use Queueable, SerializesModels;

    public Collection $changes;

    public function __construct(Collection $changes)
    {
        $this->changes = $changes;
    }

    public function build()
    {
        return $this->subject('DNS Health Check Report - ' . config('app.name'))
                    ->view('emails.dns_health');
    }
}
