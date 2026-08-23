<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class LoginOtp extends Model
{
    protected $fillable = ['user_id', 'otp_hash', 'expires_at'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Génère un nouveau code à 6 chiffres pour cet utilisateur, renvoie le
     * code EN CLAIR (uniquement pour l'envoyer par email — jamais stocké
     * tel quel). Même logique de rétention que PasswordResetOtp::generateFor().
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
     * ligne est immédiatement SUPPRIMÉE (usage unique) — même logique que
     * PasswordResetOtp::verifyFor().
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