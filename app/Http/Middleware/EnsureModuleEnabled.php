<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * ─── AJOUT : bloque l'accès à un groupe de routes si le module
 * correspondant est désactivé pour l'établissement de l'utilisateur
 * connecté — même si la requête arrive directement sur l'URL, sans
 * passer par la sidebar (qui ne l'afficherait de toute façon pas).
 *
 * ─── CORRECTIF DE ROBUSTESSE : module_enabled() (et donc
 * current_establishment_id() qu'elle appelle en interne) peut lever une
 * exception dans un cas limite (ex: token d'un type de compte qui n'a pas
 * d'établissement, contexte mal formé). Avant ce correctif, une telle
 * exception remontait telle quelle et faisait planter TOUTE l'application
 * avec une page d'erreur Laravel brute — inacceptable pour un simple
 * contrôle d'accès. Elle est maintenant systématiquement interceptée et
 * traduite en un refus propre (403), jamais en crash.
 *
 * Usage : ->middleware('module:canteen')
 */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $moduleKey)
    {
        try {
            $isEnabled = module_enabled($moduleKey);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("EnsureModuleEnabled : erreur lors de la résolution du module '{$moduleKey}' — accès refusé par sécurité. " . $e->getMessage());

            return response()->json([
                'status'  => 'error',
                'message' => "Impossible de vérifier l'accès à ce module pour votre compte. Reconnectez-vous ou contactez le support DexSchool."
            ], 403);
        }

        if (!$isEnabled) {
            return response()->json([
                'status'  => 'error',
                'message' => "Ce module n'est pas activé pour votre établissement. Contactez le support DexSchool pour l'activer."
            ], 403);
        }

        return $next($request);
    }
}