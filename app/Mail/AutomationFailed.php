<?php

namespace App\Mail;

use App\Models\Automation;
use App\Models\Certificate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AutomationFailed extends Mailable
{
    use Queueable, SerializesModels;

    public $automation;
    public $certificate;
    public $errorMessage;

    /**
     * Create a new message instance.
     */
    public function __construct(Automation $automation, Certificate $certificate, $errorMessage)
    {
        $this->automation = $automation;
        $this->certificate = $certificate;
        $this->errorMessage = $errorMessage;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Automation Failed: {$this->automation->type} for {$this->automation->domain->name}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.automation_failed',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
