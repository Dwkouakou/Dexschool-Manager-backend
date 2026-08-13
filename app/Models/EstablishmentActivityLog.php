<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstablishmentActivityLog extends Model
{
    protected $fillable = [
        'establishment_id', 'user_id', 'actor_name', 'actor_role',
        'action', 'description', 'subject_type', 'subject_id', 'ip_address',
    ];

    /**
     * Enregistre une action, depuis n'importe quel contrôleur métier :
     *
     * EstablishmentActivityLog::record(
     *     auth()->user(),
     *     'student.created',
     *     "A créé le dossier élève \"{$student->full_name}\" ({$student->matricule}).",
     *     'Student',
     *     $student->id
     * );
     *
     * Le scoping établissement est TOUJOURS automatique via
     * current_establishment_id() — jamais besoin (ni possibilité) de le
     * passer explicitement, pour éviter tout oubli/erreur de scoping.
     */
    public static function record(
        $actor,
        string $action,
        string $description,
        ?string $subjectType = null,
        ?int $subjectId = null
    ): void {
        // Récupère le nom du premier rôle Spatie de l'acteur, si dispo,
        // pour figer "qui" a fait l'action dans quel contexte (ex: Comptable,
        // Secrétaire...) — purement informatif, jamais utilisé pour la sécurité.
        $actorRole = null;
        if ($actor && method_exists($actor, 'getRoleNames')) {
            $actorRole = $actor->getRoleNames()->first();
        }

        static::create([
            'establishment_id' => current_establishment_id(),
            'user_id'          => $actor?->id,
            'actor_name'       => $actor?->name ?? 'Système',
            'actor_role'       => $actorRole,
            'action'           => $action,
            'description'      => $description,
            'subject_type'     => $subjectType,
            'subject_id'       => $subjectId,
            'ip_address'       => request()?->ip(),
        ]);
    }
}