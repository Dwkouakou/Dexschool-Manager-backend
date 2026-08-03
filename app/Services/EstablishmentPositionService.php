<?php

namespace App\Services;

use App\Models\Establishment;
use App\Models\Personel\Positions;
use Illuminate\Support\Str;

/**
 * Crée automatiquement le catalogue de postes RH par défaut pour un
 * établissement, au moment de sa création — sur le même principe que
 * EstablishmentRoleService pour les rôles Spatie.
 */
class EstablishmentPositionService
{
    private const DEFAULT_POSITIONS = [
        'Directeur',
        'Surveillant',
        'Comptable',
        'Enseignant',
        'Chauffeur',
        'Bibliothécaire',
        'Secrétaire',
    ];

    /**
     * Crée le catalogue de postes par défaut pour l'établissement donné.
     * Idempotent : peut être rappelé sans risque de doublon.
     */
    public function createDefaultPositions(Establishment $establishment): void
    {
        foreach (self::DEFAULT_POSITIONS as $positionName) {
            $slug = Str::slug($positionName);

            // ─── Recherche SANS le scope global — indispensable ici : cette
            // méthode est appelée pour un établissement qui n'est PAS
            // forcément celui actuellement "actif" pour l'utilisateur
            // connecté (ex: création d'un établissement affilié pendant
            // que l'admin est encore sur son établissement d'origine).
            // Une requête scopée ajouterait silencieusement un filtre
            // contradictoire (establishment_id = celui de l'admin, PAS
            // celui qu'on cherche), qui ne trouverait jamais rien. ───
            $existing = Positions::withoutGlobalScopes()
                ->where('establishment_id', $establishment->id)
                ->where('slug', $slug)
                ->first();

            if ($existing) {
                continue;
            }

            // ─── Création par ASSIGNATION DIRECTE, pas par mass-assignment
            // (create()/firstOrCreate()) — contourne le piège classique :
            // si "establishment_id" n'est pas listé dans le $fillable du
            // modèle Positions, un create([...]) l'ignorerait silencieusement,
            // laissant le champ vide au moment où le trait BelongsToEstablishment
            // le remplit automatiquement — mais avec le MAUVAIS établissement
            // (celui de l'admin connecté, pas celui passé en paramètre ici). ───
            $position = new Positions();
            $position->establishment_id = $establishment->id;
            $position->slug             = $slug;
            $position->name             = $positionName;
            $position->is_active        = true;
            $position->save();
        }
    }
}