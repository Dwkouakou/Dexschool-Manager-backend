<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $fillable = [
        'super_admin_id', 'actor_name', 'action', 'description', 'subject_type', 'subject_id',
    ];

    /**
     * Enregistre une action rapidement, depuis n'importe quel contrôleur :
     * ActivityLog::record($request->user(), 'establishment.created', "A créé l'établissement X", 'Establishment', $id);
     */
    public static function record($actor, string $action, string $description, ?string $subjectType = null, ?int $subjectId = null): void
    {
        static::create([
            'super_admin_id' => $actor?->id,
            'actor_name'     => $actor?->name ?? 'Système',
            'action'         => $action,
            'description'    => $description,
            'subject_type'   => $subjectType,
            'subject_id'     => $subjectId,
        ]);
    }
}