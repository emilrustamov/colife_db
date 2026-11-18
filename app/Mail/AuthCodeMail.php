<?php

namespace App\Mail;

use App\Services\AuthEmailCode;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AuthCodeMail extends Mailable
{
    /**
     * @param  AuthEmailCode::PURPOSE_*  $purpose
     */
    public function __construct(
        public string $code,
        public string $purpose,
        public int $ttlMinutes,
    ) {}

    public function envelope(): Envelope
    {
        $subject = match ($this->purpose) {
            AuthEmailCode::PURPOSE_PASSWORD_RESET => 'Код сброса пароля',
            AuthEmailCode::PURPOSE_EMAIL_VERIFY => 'Код подтверждения email',
        };

        return new Envelope(
            subject: $subject.' — '.config('app.name', 'Colife'),
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.auth-code-text',
            html: 'emails.auth-code',
        );
    }
}
