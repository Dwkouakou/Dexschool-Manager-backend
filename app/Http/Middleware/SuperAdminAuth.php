<?php

namespace App\Http\Middleware;

use App\Models\SuperAdmin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SuperAdminAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
         // 1. Doit être authentifié via Sanctum
        $tokenModel = $request->user();
 
        if (!$tokenModel) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Non authentifié.'
            ], 401);
        }
 
        // 2. Le tokenable DOIT être un SuperAdmin, pas un User classique
        if (!($tokenModel instanceof SuperAdmin)) {
            return response()->json([
                'status'  => 'error',
                'message' => "Accès refusé — cet espace est réservé à l'équipe DexSchool."
            ], 403);
        }
 
        // 3. Vérifier qu'il n'est pas désactivé entre-temps
        if (!$tokenModel->is_active) {
            $tokenModel->tokens()->delete();
            return response()->json([
                'status'  => 'error',
                'message' => 'Votre compte a été désactivé.'
            ], 403);
        }
        
        return $next($request);
    }
}
