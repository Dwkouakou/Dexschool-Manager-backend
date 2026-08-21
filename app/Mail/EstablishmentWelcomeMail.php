<?php

namespace App\Mail;

use App\Models\Establishment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EstablishmentWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public Establishment $establishment;
    public User $admin;
    public string $plainPassword;

    /**
     * @param Establishment $establishment  L'établissement fraîchement créé
     * @param User          $admin          Le compte Admin créé pour cet établissement
     * @param string        $plainPassword  Le mot de passe EN CLAIR saisi au moment
     *                                      de la création — jamais stocké nulle part,
     *                                      utilisé uniquement pour cet envoi ponctuel.
     */
    public function __construct(Establishment $establishment, User $admin, string $plainPassword)
    {
        $this->establishment = $establishment;
        $this->admin = $admin;
        $this->plainPassword = $plainPassword;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Votre établissement \"{$this->establishment->name}\" est prêt — DexSchool Manager",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.establishment-welcome',
            with: [
                'establishment' => $this->establishment,
                'admin'         => $this->admin,
                'password'      => $this->plainPassword,
                'loginUrl'      => rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/') . '/admin-login',
            ],
        );
    }
}