<?php

namespace App\Http\Controllers;

use App\Mail\PasswordResetOtpMail;
use App\Models\Establishment;
use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PasswordResetController extends Controller
{
    /**
     * ÉTAPE 1 — Demande de réinitialisation.
     * Génère un code OTP et l'envoie par email si le compte existe.
     *
     * Volontairement : la réponse est TOUJOURS la même, que le compte
     * existe ou non — évite qu'on puisse deviner quelles adresses email
     * sont enregistrées dans le système (énumération de comptes).
     *
     * POST /api/auth/forgot-password
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'establishment_code' => 'required|string',
            'email'              => 'required|email',
        ]);

        $genericMessage = "Si un compte existe avec ces informations, un code de vérification vient de lui être envoyé par email.";

        try {
            $establishment = Establishment::where('code', $request->establishment_code)->first();

            if ($establishment && $establishment->is_active) {
                $user = User::where('establishment_id', $establishment->id)
                    ->where('email', $request->email)
                    ->first();

                if ($user) {
                    $code = PasswordResetOtp::generateFor($user);
                    Mail::to($user->email)->send(new PasswordResetOtpMail($user, $code));
                }
            }
        } catch (\Throwable $e) {
            // On log l'erreur pour le débug, mais on ne la révèle JAMAIS au
            // client — le message générique reste identique dans tous les
            // cas, succès comme échec technique.
            Log::error('Erreur forgotPassword : ' . $e->getMessage());
        }

        return response()->json([
            'status'  => 'success',
            'message' => $genericMessage,
        ]);
    }

    /**
     * ÉTAPE 2 — Vérification du code + définition du nouveau mot de passe.
     *
     * POST /api/auth/reset-password
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'establishment_code' => 'required|string',
            'email'              => 'required|email',
            'otp_code'           => 'required|string|size:6',
            'password'           => 'required|string|min:6|confirmed',
        ], [
            'otp_code.size'        => "Le code doit contenir exactement 6 chiffres.",
            'password.confirmed'   => "La confirmation du mot de passe ne correspond pas.",
        ]);

        $establishment = Establishment::where('code', $request->establishment_code)->first();

        if (!$establishment) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Code établissement invalide.',
            ], 404);
        }

        $user = User::where('establishment_id', $establishment->id)
            ->where('email', $request->email)
            ->first();

        if (!$user) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Code de vérification invalide ou expiré.',
            ], 422);
        }

        // ─── Message volontairement identique à "utilisateur introuvable"
        // ci-dessus — ne révèle jamais si c'est le compte ou le code qui
        // pose problème (même logique anti-énumération qu'à l'étape 1).
        if (!PasswordResetOtp::verifyFor($user, $request->otp_code)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Code de vérification invalide ou expiré.',
            ], 422);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        // Déconnecte immédiatement toutes les sessions actives — un
        // changement de mot de passe doit invalider tout accès existant,
        // y compris celui d'un éventuel accès non autorisé antérieur.
        $user->tokens()->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Votre mot de passe a été réinitialisé avec succès. Vous pouvez maintenant vous connecter.',
        ]);
    }
}