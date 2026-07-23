<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIsSchoolAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
                $user = $request->user();

        if (!$user) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Non authentifié.'
            ], 401);
        }

        $isAdmin = $user->roles()->where('slug', 'admin')->exists();

        if (!$isAdmin) {
            return response()->json([
                'status'  => 'error',
                'message' => "Seul l'administrateur de l'établissement peut effectuer cette action."
            ], 403);
        }

        return $next($request);
    }
}
