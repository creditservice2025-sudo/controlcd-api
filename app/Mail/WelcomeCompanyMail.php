<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeCompanyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User    $user,
        public readonly Company $company,
        public readonly string  $plainPassword,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '¡Bienvenido a Control-C&D! - Tus credenciales de acceso',
        );
    }

    public function content(): Content
    {
        $emailParts = explode('@', $this->user->email);
        $username = $emailParts[0];

        return new Content(
            view: 'emails.welcome-company',
            with: [
                'userName'      => $this->user->name,
                'username'      => $username,
                'companyName'   => $this->company->name,
                'userEmail'     => $this->user->email,
                'password'      => $this->plainPassword,
                'appUrl'        => env('FRONTEND_URL', config('app.url')),
            ],
        );
    }
}
