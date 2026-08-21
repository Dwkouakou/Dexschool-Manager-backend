<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class PasswordResetOtp extends Model
{
    protected $fillable = ['user_id', 'otp_hash', 'expires_at'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Génère un nouveau code à 6 chiffres pour cet utilisateur, renvoie le
     * code EN CLAIR (uniquement pour l'envoyer par email — jamais stocké
     * tel quel).
     *
     * ─── Rétention : rien ne doit traîner inutilement en base ───
     * 1. Supprime tout ancien code de CET utilisateur (une nouvelle
     *    demande annule les précédentes, qu'elles aient été utilisées ou
     *    non — un seul code actif possible à la fois par compte).
     * 2. Supprime au passage TOUS les codes expirés de TOUS les
     *    utilisateurs (nettoyage léger et gratuit à chaque appel, pas
     *    besoin de tâche planifiée séparée pour une table aussi simple).
     */
    public static function generateFor(User $user): string
    {
        static::where('user_id', $user->id)->delete();
        static::where('expires_at', '<', now())->delete();

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        static::create([
            'user_id'    => $user->id,
            'otp_hash'   => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
        ]);

        return $code;
    }

    /**
     * Vérifie un code saisi pour un utilisateur donné. Si valide, la
     * ligne est immédiatement SUPPRIMÉE (pas juste marquée "utilisée") —
     * un code déjà vérifié n'a plus aucune raison d'exister en base.
     */
    public static function verifyFor(User $user, string $code): bool
    {
        $otp = static::where('user_id', $user->id)
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if (!$otp || !Hash::check($code, $otp->otp_hash)) {
            return false;
        }

        $otp->delete();

        return true;
    }
}