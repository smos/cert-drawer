<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CertHealthReport extends Mailable
{
    use Queueable, SerializesModels;

    public array $changes;
    public array $expiryAlerts;
    public array $thresholds;

    public function __construct(array $changes, array $expiryAlerts = [])
    {
        $this->changes = $changes;
        $this->expiryAlerts = $expiryAlerts;

        $settings = \App\Models\Setting::all()->pluck('value', 'key');
        $this->thresholds = [
            'yellow' => (int) ($settings['expiry_yellow'] ?? 30),
            'orange' => (int) ($settings['expiry_orange'] ?? 20),
            'red'    => (int) ($settings['expiry_red'] ?? 10),
        ];
    }

    public function build()
    {
        return $this->subject('Certificate Health Alert - ' . config('app.name'))
                    ->view('emails.cert_health');
    }
}
