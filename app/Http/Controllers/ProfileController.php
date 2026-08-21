<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    /**
     * Renvoie le profil complet de l'utilisateur actuellement connecté.
     * GET /api/me/profile
     */
    public function show(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();

        return response()->json([
            'status' => 'success',
            'user'   => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'photo' => $user->photo ?? null,
                // ─── CORRECTIF : uniquement les rôles de l'établissement
                // ACTUELLEMENT CONSULTÉ. Sans ce filtre, un utilisateur avec
                // plusieurs rôles sur différents établissements du groupe
                // (accès multi-cycle) voyait s'afficher un rôle qui ne
                // s'applique pas du tout à l'établissement où il se trouve.
                'roles' => $user->roles()
                    ->where('establishment_id', current_establishment_id())
                    ->pluck('name'),
            ],
        ]);
    }

    /**
     * Modifie les informations personnelles de l'utilisateur connecté
     * (nom, email, téléphone, photo). Le mot de passe se change via une
     * méthode séparée pour bien isoler cette action sensible.
     * POST /api/me/profile
     */
    public function update(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:25'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:2048'],
        ]);

        if ($request->hasFile('photo')) {
            // Supprime l'ancienne photo si elle existe, avant d'enregistrer la nouvelle
            if ($user->photo) {
                $oldPath = str_replace('/storage/', '', $user->photo);
                if (\Illuminate\Support\Facades\Storage::disk('public')->exists($oldPath)) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($oldPath);
                }
            }
            $path = $request->file('photo')->store('users/photos', 'public');
            $validated['photo'] = '/storage/' . $path;
        }

        $user->update($validated);

        return response()->json([
            'status'  => 'success',
            'message' => 'Profil mis à jour avec succès.',
            'user'    => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'photo' => $user->photo,
            ],
        ]);
    }

    /**
     * Change le mot de passe de l'utilisateur connecté — exige l'ancien
     * mot de passe pour confirmer l'identité (même connecté, par sécurité).
     * PUT /api/me/password
     */
    public function updatePassword(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password'      => ['required', 'string', 'min:6', 'confirmed'],
        ], [
            'new_password.confirmed' => 'La confirmation du nouveau mot de passe ne correspond pas.',
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Le mot de passe actuel est incorrect.',
            ], 422);
        }

        $user->update(['password' => Hash::make($validated['new_password'])]);

        // Révoque tous les autres tokens actifs (déconnecte les autres sessions),
        // ne garde que celui utilisé pour cette requête
        $currentTokenId = $user->currentAccessToken()?->id;
        $user->tokens()->where('id', '!=', $currentTokenId)->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Mot de passe modifié avec succès. Vos autres sessions ont été déconnectées.',
        ]);
    }

    /**
     * Renvoie la liste plate des permissions de l'utilisateur connecté
     * (via tous ses rôles cumulés). Chargée une fois côté frontend après
     * connexion, et utilisée par ProtectedRoute pour sécuriser les pages
     * et par la Sidebar pour n'afficher que les liens accessibles.
     * GET /api/me/permissions
     */
    public function permissions(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();

        // ─── CORRECTIF : ne prendre en compte QUE les rôles de l'utilisateur
        // rattachés à l'établissement ACTUELLEMENT CONSULTÉ, pas tous ses
        // rôles cumulés sur le groupe entier. Sans ce filtre, un utilisateur
        // avec accès multi-cycle (ex: Comptable sur le parent + Enseignant
        // sur un établissement enfant) voyait les boutons/pages liés à SON
        // rôle Enseignant même en étant sur le parent, où ce rôle n'a jamais
        // été attribué.
        $permissions = $user->roles()
            ->where('establishment_id', current_establishment_id())
            ->with('permissions')
            ->get()
            ->flatMap(fn($role) => $role->permissions)
            ->pluck('name')
            ->unique()
            ->values();

        return response()->json([
            'status'      => 'success',
            'permissions' => $permissions,
        ]);
    }
}