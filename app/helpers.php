<?php

use Illuminate\Support\Facades\Auth;

if (!function_exists('current_establishment_id')) {
    function current_establishment_id(): ?int
    {
        $user = Auth::user();
        if (!$user) return null;

        // ─── CORRECTIF CRITIQUE : renvoie l'établissement ACTUELLEMENT
        // CONSULTÉ (viewing_establishment_id, rempli uniquement par
        // AffiliatedEstablishmentController::switchTo() après vérification
        // d'accès légitime), et seulement s'il n'y en a pas, l'établissement
        // d'ORIGINE du compte (establishment_id).
        //
        // Sans ce correctif, TOUTE fonction qui dépend de celle-ci
        // (current_active_year_id, current_establishment_prefix,
        // current_school_year_short, et tout contrôleur qui scope
        // manuellement via current_establishment_id() plutôt que par le
        // trait Eloquent BelongsToEstablishment) continuerait de raisonner
        // sur l'établissement d'origine même après un switch — exactement
        // le même bug que celui déjà corrigé sur le trait, mais resté actif
        // ici puisque c'est un point de scope totalement séparé. ───
        return $user->viewing_establishment_id ?? $user->establishment_id ?? null;
    }
}

if (!function_exists('current_active_year_id')) {
    /**
     * Retourne l'id de l'année active de l'établissement du user connecté.
     */
    function current_active_year_id(): ?int
    {
        $establishmentId = current_establishment_id();
        if (!$establishmentId) return null;

        return \App\Models\Academic\AcademicYears::where('establishment_id', $establishmentId)
            ->where('is_active', true)
            ->value('id');
    }
}


if (!function_exists('current_establishment_prefix')) {
    /**
     * Retourne le préfixe lettres du code établissement (ex: 'MFO-001' → 'MFO').
     */
    function current_establishment_prefix(): ?string
    {
        $establishmentId = current_establishment_id();
        if (!$establishmentId) return null;

        $code = \App\Models\Establishment::where('id', $establishmentId)->value('code');
        if (!$code) return null;

        // Prend la partie avant le premier tiret, en majuscules
        return strtoupper(explode('-', $code)[0]);
    }
}

if (!function_exists('current_school_year_short')) {
    /**
     * Retourne l'année scolaire active au format compact (ex: '2025-2026' → '2526').
     * Se base sur le champ 'name' de l'année active de l'établissement.
     */
    function current_school_year_short(): string
    {
        $establishmentId = current_establishment_id();

        $year = \App\Models\Academic\AcademicYears::where('establishment_id', $establishmentId)
            ->where('is_active', true)
            ->first();

        if ($year && preg_match('/(\d{4})\D+(\d{4})/', $year->name, $m)) {
            // "2025-2026" → "2526"
            return substr($m[1], -2) . substr($m[2], -2);
        }

        // Repli : année civile courante sur 2 chiffres, doublée (ex: "2626")
        $y = date('y');
        return $y . $y;
    }
}

if (!function_exists('current_viewing_year_id')) {
    /**
     * Année que le user consulte actuellement.
     * - User standard : toujours l'année active (fixée à la connexion).
     * - Admin : l'année qu'il a choisie (peut différer de l'année active).
     */
    function current_viewing_year_id(): ?int
    {
        $user = Auth::user();
        if (!$user || empty($user->establishment_id)) return null;

        return $user->viewing_year_id ?? current_active_year_id();
    }
}

if (!function_exists('assert_writable_year')) {
    /**
     * Bloque toute création/modification si l'utilisateur (admin) consulte
     * une année différente de l'année active réelle de l'établissement.
     * À appeler en tête de chaque store()/update() qui touche des données d'année.
     */
    function assert_writable_year(): void
    {
        $viewing = current_viewing_year_id();
        $active  = current_active_year_id();

        if ($viewing !== $active) {
            abort(response()->json([
                'status'  => 'error',
                'message' => "Vous consultez une année archivée. Aucune création ou modification n'est autorisée ici.",
            ], 403));
        }
    }
}

// ─── AJOUT : résolution de l'état d'un module pour un établissement ───
// Vérifie d'abord s'il existe une EXCEPTION explicite pour cet
// établissement (table establishment_module_access) — si oui, elle
// prime toujours. Sinon, retombe sur le défaut du catalogue (table
// modules). Si le module n'existe même pas dans le catalogue, on
// considère par sécurité qu'il est actif (évite de bloquer un module
// historique qu'on aurait oublié d'ajouter au catalogue par erreur).
if (!function_exists('module_enabled')) {
    function module_enabled(string $moduleKey, ?int $establishmentId = null): bool
    {
        $establishmentId = $establishmentId ?? current_establishment_id();

        $module = \App\Models\Module::where('key', $moduleKey)->first();

        if (!$module) {
            return true;
        }

        $override = \App\Models\EstablishmentModuleAccess::where('establishment_id', $establishmentId)
            ->where('module_id', $module->id)
            ->first();

        if ($override) {
            return (bool) $override->is_enabled;
        }

        return (bool) $module->is_active_by_default;
    }
}

// ─── AJOUT : renvoie la carte complète {clé_module: bool} pour un
// établissement — utilisé par l'endpoint /me/enabled-modules pour
// construire dynamiquement la sidebar côté frontend.
if (!function_exists('get_enabled_modules_map')) {
    function get_enabled_modules_map(?int $establishmentId = null): array
    {
        $establishmentId = $establishmentId ?? current_establishment_id();

        $modules = \App\Models\Module::all();
        $overrides = \App\Models\EstablishmentModuleAccess::where('establishment_id', $establishmentId)
            ->pluck('is_enabled', 'module_id');

        $map = [];
        foreach ($modules as $module) {
            $map[$module->key] = $overrides->has($module->id)
                ? (bool) $overrides->get($module->id)
                : (bool) $module->is_active_by_default;
        }

        return $map;
    }
}