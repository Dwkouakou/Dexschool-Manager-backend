<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Volontairement SANS le trait BelongsToEstablishment : la page Paramètres
 * d'un établissement racine doit pouvoir lire/écrire la grille des
 * établissements affiliés. Le contrôle d'accès est fait dans le contrôleur.
 */
class EstablishmentLevelFee extends Model
{
    public const STATUS_AFFECTE     = 'affecte';
    public const STATUS_NON_AFFECTE = 'non_affecte';

    protected $table = 'establishment_level_fees';

    protected $fillable = [
        'establishment_id', 'level_id', 'assignment_status',
        'registration_fee', 'tuition_fee', 'updated_by',
    ];

    protected $casts = [
        'registration_fee' => 'integer',
        'tuition_fee'      => 'integer',
    ];
}