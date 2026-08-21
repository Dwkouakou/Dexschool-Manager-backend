<?php

namespace App\Http\Controllers;

use App\Models\Academic\AcademicYears;
use App\Models\Establishment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AdminApiController extends Controller
{


    // ─────────────────────────────────────────────────────────────────────────
    // CONNEXION UTILISATEUR STANDARD (non-admin) — 1 seule étape
    // ─────────────────────────────────────────────────────────────────────────
    public function loginUser(Request $request)
    {
        try {
            $request->validate([
                'establishment_code' => 'required|string',
                'login'              => 'required|string',
                'password'           => 'required|string',
            ]);

            $establishment = Establishment::where('code', $request->establishment_code)->first();

            if (!$establishment) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Code établissement invalide ou inexistant.'
                ], 404);
            }

            if (!$establishment->is_active) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Cet établissement est actuellement désactivé. Contactez le support DexSchool.'
                ], 403);
            }

            $user = User::where('establishment_id', $establishment->id)
                ->where(function ($query) use ($request) {
                    $query->where('email', $request->login)
                        ->orWhere('phone', $request->login);
                })->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Identifiant ou mot de passe incorrect.'
                ], 401);
            }

            $isAdmin = $user->roles()->where('slug', 'admin')->exists();
            if ($isAdmin) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Ce compte est administrateur. Veuillez utiliser l'accès administrateur."
                ], 403);
            }

            // ─── CORRECTIF : détermine l'établissement où la personne a
            // RÉELLEMENT un rôle actif, avant de choisir où l'envoyer.
            //
            // Contexte du bug corrigé : un Admin peut créer un employé sur
            // l'établissement parent, puis lui attribuer son rôle
            // uniquement sur un établissement ENFANT du groupe (ex: après
            // une réorganisation). Le compte se connecte alors avec succès
            // (il a bien un rôle "quelque part"), mais sans ce correctif il
            // atterrissait systématiquement sur l'établissement d'ORIGINE
            // (celui du code établissement saisi) — où il n'a AUCUN rôle,
            // donc AUCUNE permission, donc un dashboard vide et inutilisable,
            // sans aucun message d'erreur pour expliquer pourquoi.
            //
            // Le correctif : si aucun rôle n'existe sur l'établissement
            // d'origine, on cherche un rôle actif ailleurs dans le MÊME
            // groupe scolaire, et on connecte directement la personne sur
            // cet établissement-là (viewing_establishment_id ajusté en
            // conséquence) plutôt que de la laisser bloquée sur un
            // établissement où elle n'a aucun accès.
            $hasRoleOnOrigin = $user->roles()->where('establishment_id', $establishment->id)->exists();

            $targetEstablishment = $establishment;

            if (!$hasRoleOnOrigin) {
                $rootId = $establishment->parent_establishment_id ?? $establishment->id;

                $groupEstablishmentIds = Establishment::where('id', $rootId)
                    ->orWhere('parent_establishment_id', $rootId)
                    ->pluck('id');

                $roleElsewhereInGroup = \Spatie\Permission\Models\Role::whereIn('establishment_id', $groupEstablishmentIds)
                    ->whereHas('users', fn($q) => $q->where('users.id', $user->id))
                    ->first();

                if (!$roleElsewhereInGroup) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => "Votre compte n'a pas encore de rôle assigné, ni sur cet établissement ni sur le reste du groupe scolaire. Contactez votre administrateur d'établissement."
                    ], 403);
                }

                $targetEstablishment = Establishment::find($roleElsewhereInGroup->establishment_id);
            }

            $activeYear = AcademicYears::where('establishment_id', $targetEstablishment->id)
                ->where('is_active', true)
                ->first();

            if (!$activeYear) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Aucune année scolaire active pour le moment sur \"{$targetEstablishment->name}\". Contactez votre administrateur."
                ], 403);
            }

            $user->tokens()->delete();

            // ─── AJOUT CRITIQUE : réinitialise le contexte de switch groupe
            // scolaire à CHAQUE connexion, puis le positionne sur
            // l'établissement CIBLE déterminé ci-dessus (null si c'est
            // l'origine, sinon l'établissement où le rôle a été trouvé).
            // Assignation DIRECTE (pas ->update()) pour contourner le piège
            // classique : si "viewing_establishment_id" n'est pas listé dans
            // le $fillable du modèle User, ->update([...]) l'ignorerait
            // silencieusement (aucune erreur, mais rien n'est écrit). ───
            $user->viewing_year_id = $activeYear->id;
            $user->viewing_establishment_id = $targetEstablishment->id === $establishment->id
                ? null
                : $targetEstablishment->id;
            $user->save();

            $token = $user->createToken('main_token', ['*'])->plainTextToken;

            $roleName = null;
            try {
                $roleName = $user->roles()
                    ->where('establishment_id', $targetEstablishment->id)
                    ->first()?->name;
            } catch (\Throwable $e) {
                Log::warning("User {$user->id} sans rôle Spatie : " . $e->getMessage());
            }

            return response()->json([
                'status'        => 'success',
                'token'         => $token,
                'user'          => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role'  => $roleName,
                ],
                'establishment' => [
                    'id'   => $targetEstablishment->id,
                    'name' => $targetEstablishment->name,
                    'code' => $targetEstablishment->code,
                ],
                'active_year'   => $activeYear,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            Log::error('Erreur loginUser : ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur interne du serveur.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ÉTAPE 1 — Connexion avec Code Établissement
    // ─────────────────────────────────────────────────────────────────────────
    public function loginWithEstablishment(Request $request)
    {
        try {
            $request->validate([
                'establishment_code' => 'required|string',
                'login'              => 'required|string',
                'password'           => 'required|string',
            ]);

            $establishment = Establishment::where('code', $request->establishment_code)->first();

            if (!$establishment) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Code établissement invalide ou inexistant.'
                ], 404);
            }

            if (!$establishment->is_active) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Cet établissement est actuellement désactivé. Contactez le support DexSchool.'
                ], 403);
            }

            $user = User::where('establishment_id', $establishment->id)
                ->where(function ($query) use ($request) {
                    $query->where('email', $request->login)
                          ->orWhere('phone', $request->login);
                })->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Identifiant ou mot de passe incorrect.'
                ], 401);
            }

            $isAdmin = $user->roles()->where('slug', 'admin')->exists();
            if (!$isAdmin) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Veuillez utiliser la page de connexion standard.'
                ], 403);
            }

            $user->tokens()->where('name', 'temp_token')->delete();

            // ─── AJOUT : réinitialise le switch groupe scolaire dès l'étape 1,
            // assignation DIRECTE (contourne le piège du $fillable). ───
            $user->viewing_establishment_id = null;
            $user->save();

            $tempToken = $user->createToken('temp_token', ['select-academic-year'])->plainTextToken;

            $academicYears = AcademicYears::where('establishment_id', $establishment->id)
                ->orderBy('start_date', 'desc')
                ->get();

            $roleName = null;
            try {
                $roleName = $user->getRoleNames()->first();
            } catch (\Throwable $e) {
                Log::warning("User {$user->id} sans rôle Spatie : " . $e->getMessage());
            }

            return response()->json([
                'status'         => 'success',
                'temp_token'     => $tempToken,
                'user'           => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role'  => $roleName,
                ],
                'establishment'  => [
                    'id'   => $establishment->id,
                    'name' => $establishment->name,
                    'code' => $establishment->code,
                ],
                'academic_years' => $academicYears,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            Log::error('Erreur loginWithEstablishment : ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur interne du serveur.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ÉTAPE 2 — Sélection de l'année
    // ─────────────────────────────────────────────────────────────────────────
    public function selectYear(Request $request)
    {
        try {
            if (!$request->user()->tokenCan('select-academic-year')) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Ce jeton ne permet pas de sélectionner une année. Reconnectez-vous.'
                ], 403);
            }

            $request->validate([
                'academic_year_id' => 'required|exists:academic_years,id',
            ]);

            $user = $request->user();

            $year = AcademicYears::where('id', $request->academic_year_id)
                ->where('establishment_id', $user->establishment_id)
                ->first();

            if (!$year) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Cette année scolaire n'appartient pas à votre établissement."
                ], 403);
            }

            $request->user()->currentAccessToken()->delete();

            // ─── AJOUT : re-confirme le reset ici aussi, assignation DIRECTE
            // (filet de sécurité supplémentaire, au cas où l'étape 1 aurait
            // été contournée + contourne le piège du $fillable). ───
            $user->viewing_year_id = $year->id;
            $user->viewing_establishment_id = null;
            $user->save();

            $finalToken = $user->createToken('main_token', ['*'])->plainTextToken;

            $establishmentData = null;
            try {
                $user->load('establishment');
                $establishmentData = $user->establishment;
            } catch (\Throwable $e) {
                Log::warning("Impossible de charger establishment pour user {$user->id} : " . $e->getMessage());
            }

            $yearData = $year->toArray();
            try {
                $yearData['periods'] = $year->periods;
            } catch (\Throwable $e) {
                Log::warning("Impossible de charger periods pour year {$year->id} : " . $e->getMessage());
                $yearData['periods'] = [];
            }

            $roleName = null;
            try {
                $roleName = $user->getRoleNames()->first();
            } catch (\Throwable $e) {
                Log::warning("User {$user->id} sans rôle Spatie : " . $e->getMessage());
            }

            return response()->json([
                'status'        => 'success',
                'token'         => $finalToken,
                'user'          => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role'  => $roleName,
                ],
                'establishment' => $establishmentData,
                'active_year'   => $yearData,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            Log::error('❌ Erreur selectYear : ' . $e->getMessage(), [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur interne du serveur.',
                'debug'   => config('app.debug') ? [
                    'error' => $e->getMessage(),
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                ] : null,
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ÉTAPE 2 BIS — Création d'une nouvelle année scolaire
    // ─────────────────────────────────────────────────────────────────────────
    public function createYearForEstablishment(Request $request)
    {
        try {
            if (!$request->user()->tokenCan('select-academic-year')) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Ce jeton ne permet pas de créer une année.'
                ], 403);
            }

            $user = $request->user();

            $request->validate([
                'name' => [
                    'required', 'string', 'max:255',
                    Rule::unique('academic_years', 'name')
                        ->where(fn($q) => $q->where('establishment_id', $user->establishment_id)),
                ],
                'start_date' => 'required|date',
                'end_date'   => 'required|date|after:start_date',
            ], [
                'name.unique'    => "Une année scolaire avec ce nom existe déjà dans votre établissement.",
                'end_date.after' => "La date de fin doit être postérieure à la date de début.",
            ]);

            $newYear = AcademicYears::create([
                'establishment_id' => $user->establishment_id,
                'name'             => $request->name,
                'start_date'       => $request->start_date,
                'end_date'         => $request->end_date,
                'is_active'        => false,
                'is_archived'      => false,
            ]);

            return response()->json([
                'status'  => 'success',
                'data'    => $newYear,
                'message' => 'Année scolaire créée avec succès.',
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            Log::error('Erreur createYearForEstablishment : ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur interne du serveur.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DÉCONNEXION
    // ─────────────────────────────────────────────────────────────────────────
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Déconnexion réussie.'
        ]);
    }

    public function switchViewingYear(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'academic_year_id' => 'required|exists:academic_years,id',
        ]);

        $year = AcademicYears::where('id', $request->academic_year_id)
            ->where('establishment_id', $user->establishment_id)
            ->first();

        if (!$year) {
            return response()->json([
                'status'  => 'error',
                'message' => "Cette année n'appartient pas à votre établissement."
            ], 403);
        }

        $user->viewing_year_id = $year->id;
        $user->save();

        return response()->json([
            'status'  => 'success',
            'message' => "Vous consultez maintenant l'année {$year->name}.",
            'viewing_year' => $year,
        ]);
    }


    // ─────────────────────────────────────────────────────────────────────────
    // LISTE DES RÔLES DISPONIBLES POUR L'ÉTABLISSEMENT
    // ─────────────────────────────────────────────────────────────────────────
    public function listRoles(Request $request)
    {
        $user = $request->user();

        $roles = \Spatie\Permission\Models\Role::where('establishment_id', $user->establishment_id)
            ->orderBy('is_locked', 'desc')
            ->get(['id', 'name', 'slug', 'description', 'is_locked']);

        return response()->json([
            'status' => 'success',
            'roles'  => $roles,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CRÉATION D'UN UTILISATEUR STANDARD (par l'Admin de l'établissement)
    // ─────────────────────────────────────────────────────────────────────────
    public function createUser(Request $request)
    {
        $adminUser = $request->user();

        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'phone'    => 'nullable|string|unique:users,phone',
            'password' => 'required|string|min:6',
            'role_id'  => 'required|integer|exists:roles,id',
        ], [
            'email.unique' => "Cette adresse email est déjà utilisée.",
            'phone.unique' => "Ce numéro est déjà utilisé.",
        ]);

        $role = \Spatie\Permission\Models\Role::where('id', $validated['role_id'])
            ->where('establishment_id', $adminUser->establishment_id)
            ->first();

        if (!$role) {
            return response()->json([
                'status'  => 'error',
                'message' => "Ce rôle n'appartient pas à votre établissement."
            ], 422);
        }

        if ($role->slug === 'admin') {
            return response()->json([
                'status'  => 'error',
                'message' => "Impossible de créer un compte administrateur depuis ce formulaire."
            ], 403);
        }

        try {
            $newUser = DB::transaction(function () use ($validated, $adminUser, $role) {
                $user = \App\Models\User::create([
                    'establishment_id' => $adminUser->establishment_id,
                    'name'             => $validated['name'],
                    'email'            => $validated['email'],
                    'phone'            => $validated['phone'] ?? null,
                    'password'         => \Illuminate\Support\Facades\Hash::make($validated['password']),
                ]);

                $user->assignRole($role);

                return $user;
            });

            return response()->json([
                'status'  => 'success',
                'message' => "Utilisateur {$newUser->name} créé avec succès.",
                'user'    => [
                    'id'    => $newUser->id,
                    'name'  => $newUser->name,
                    'email' => $newUser->email,
                    'phone' => $newUser->phone,
                    'role'  => $role->name,
                ],
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => "Erreur lors de la création.",
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}