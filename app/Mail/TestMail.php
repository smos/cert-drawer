<?php

namespace App\Mail;

use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function build()
    {
        $fromName = Setting::where('key', 'mail_from_name')->value('value') ?? config('mail.from.name');
        return $this->subject('Test Email from ' . $fromName)
                    ->html('<h1>Success!</h1><p>If you are reading this, your SMTP settings are configured correctly.</p><p>Sent at: ' . now()->toDateTimeString() . '</p>');
    }
}
