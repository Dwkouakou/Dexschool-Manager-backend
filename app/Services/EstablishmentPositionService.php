<?php

namespace App\Services;

use App\Models\Establishment;
use App\Models\Personel\Positions;
use Illuminate\Support\Str;

/**
 * Crée automatiquement le catalogue de postes RH par défaut pour un
 * établissement, au moment de sa création — sur le même principe que
 * EstablishmentRoleService pour les rôles Spatie.
 *
 * "positions" est une table scopée par établissement (chaque école a ses
 * propres postes) : ce service évite d'avoir à relancer un seeder manuel
 * à chaque fois qu'une nouvelle école est créée.
 */
class EstablishmentPositionService
{
    /**
     * Liste des postes RH de base fournis à toute nouvelle école.
     * L'admin pourra ensuite en ajouter d'autres librement depuis l'interface.
     */
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
     * Idempotent : peut être rappelé sans risque de doublon (firstOrCreate).
     */
    public function createDefaultPositions(Establishment $establishment): void
    {
        foreach (self::DEFAULT_POSITIONS as $positionName) {
            Positions::firstOrCreate(
                [
                    'establishment_id' => $establishment->id,
                    'slug'              => Str::slug($positionName),
                ],
                [
                    'name'      => $positionName,
                    'is_active' => true,
                ]
            );
        }
    }
}