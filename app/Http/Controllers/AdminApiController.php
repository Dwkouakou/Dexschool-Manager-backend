<?php

namespace App\Http\Controllers;

use App\Mail\LoginOtpMail;
use App\Models\Academic\AcademicYears;
use App\Models\Establishment;
use App\Models\LoginOtp;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class AdminApiController extends Controller
{


    // ─────────────────────────────────────────────────────────────────────────
    // CONNEXION UTILISATEUR STANDARD (non-admin) — ÉTAPE 1 : identifiants
    // ─── MODIFIÉ : ne délivre plus de token directement. Une fois le mot
    // de passe validé, un OTP est envoyé par email — le vrai token n'est
    // délivré qu'après vérification de ce code via verifyLoginOtp().
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

            // ─── AJOUT : envoi de l'OTP à la place de la délivrance
            // immédiate du token. Aucun token n'existe encore à ce stade —
            // le frontend doit maintenant appeler /auth/verify-login-otp
            // avec le même establishment_code + login pour continuer.
            if (!$user->email) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Ce compte n'a pas d'adresse email enregistrée — impossible d'envoyer le code de connexion. Contactez votre administrateur."
                ], 422);
            }

            $code = LoginOtp::generateFor($user);
            Mail::to($user->email)->send(new LoginOtpMail($user, $code));

            return response()->json([
                'status'       => 'success',
                'otp_required' => true,
                'message'      => "Un code de vérification a été envoyé à l'adresse email associée à ce compte.",
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

    /**
     * ─── AJOUT : logique de délivrance du token utilisateur standard,
     * extraite de l'ancien loginUser() — inchangée dans le fond, appelée
     * maintenant uniquement APRÈS vérification réussie de l'OTP, depuis
     * verifyLoginOtp().
     */
    private function issueStandardUserSession(User $user, Establishment $establishment)
    {
        // ─── CORRECTIF (déjà existant) : détermine l'établissement où la
        // personne a RÉELLEMENT un rôle actif, avant de choisir où l'envoyer.
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
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ÉTAPE 1 — Connexion Admin avec Code Établissement
    // ─── MODIFIÉ : ne délivre plus de temp_token directement. Une fois le
    // mot de passe validé, un OTP est envoyé — le temp_token n'est délivré
    // qu'après vérification via verifyLoginOtp().
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

            // ─── AJOUT : envoi de l'OTP à la place de la délivrance
            // immédiate du temp_token.
            if (!$user->email) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Ce compte n'a pas d'adresse email enregistrée — impossible d'envoyer le code de connexion. Contactez le support DexSchool."
                ], 422);
            }

            $code = LoginOtp::generateFor($user);
            Mail::to($user->email)->send(new LoginOtpMail($user, $code));

            return response()->json([
                'status'       => 'success',
                'otp_required' => true,
                'message'      => "Un code de vérification a été envoyé à l'adresse email associée à ce compte.",
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

    /**
     * ─── AJOUT : logique de délivrance du temp_token admin, extraite de
     * l'ancien loginWithEstablishment() — inchangée dans le fond, appelée
     * maintenant uniquement APRÈS vérification réussie de l'OTP.
     */
    private function issueAdminTempTokenSession(User $user, Establishment $establishment)
    {
        $user->tokens()->where('name', 'temp_token')->delete();

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
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJOUT : VÉRIFICATION DE L'OTP DE CONNEXION — unique point d'entrée
    // pour les deux flux (admin et standard). Détecte lui-même de quel
    // type de compte il s'agit via la même vérification de rôle déjà
    // utilisée par loginWithEstablishment()/loginUser(), et renvoie la
    // réponse correspondante (temp_token+années pour l'admin, token final
    // direct pour l'utilisateur standard).
    // POST /api/auth/verify-login-otp
    // ─────────────────────────────────────────────────────────────────────────
    public function verifyLoginOtp(Request $request)
    {
        try {
            $request->validate([
                'establishment_code' => 'required|string',
                'login'              => 'required|string',
                'otp_code'           => 'required|string|size:6',
            ], [
                'otp_code.size' => "Le code doit contenir exactement 6 chiffres.",
            ]);

            $establishment = Establishment::where('code', $request->establishment_code)->first();

            if (!$establishment) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Code établissement invalide ou inexistant.'
                ], 404);
            }

            $user = User::where('establishment_id', $establishment->id)
                ->where(function ($query) use ($request) {
                    $query->where('email', $request->login)
                          ->orWhere('phone', $request->login);
                })->first();

            if (!$user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Code de vérification invalide ou expiré.'
                ], 422);
            }

            // ─── Message volontairement identique quel que soit le motif
            // exact de l'échec — n'indique jamais si c'est le compte ou le
            // code qui pose problème (même logique anti-énumération que
            // PasswordResetController).
            if (!LoginOtp::verifyFor($user, $request->otp_code)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Code de vérification invalide ou expiré.'
                ], 422);
            }

            $isAdmin = $user->roles()->where('slug', 'admin')->exists();

            return $isAdmin
                ? $this->issueAdminTempTokenSession($user, $establishment)
                : $this->issueStandardUserSession($user, $establishment);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            Log::error('Erreur verifyLoginOtp : ' . $e->getMessage(), [
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
    // ÉTAPE 2 — Sélection de l'année (INCHANGÉ)
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
    // ÉTAPE 2 BIS — Création d'une nouvelle année scolaire (INCHANGÉ)
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
    // AJOUT : carte des modules activés pour l'établissement de l'utilisateur
    // connecté — utilisée par le frontend pour construire dynamiquement la
    // sidebar (n'affiche que les modules réellement accessibles).
    // GET /api/me/enabled-modules
    // ─────────────────────────────────────────────────────────────────────────
    public function enabledModules(Request $request)
    {
        try {
            $establishmentId = $request->user()->establishment_id;
            $map = get_enabled_modules_map($establishmentId);

            return response()->json([
                'status'  => 'success',
                'modules' => $map,
            ]);
        } catch (\Throwable $e) {
            Log::error('Erreur enabledModules : ' . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors du chargement des modules disponibles.',
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DÉCONNEXION (INCHANGÉ)
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
    // LISTE DES RÔLES DISPONIBLES POUR L'ÉTABLISSEMENT (INCHANGÉ)
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
    // CRÉATION D'UN UTILISATEUR STANDARD (par l'Admin de l'établissement) (INCHANGÉ)
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