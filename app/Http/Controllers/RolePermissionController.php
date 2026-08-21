<?php

namespace App\Http\Controllers;

use App\Support\PermissionCategories;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionController extends Controller
{
    /**
     * Liste des permissions, groupées par catégorie avec leur couleur.
     * GET /api/roles/permissions-grouped
     */
    public function permissionsGrouped()
    {
        $permissions = Permission::whereNotNull('group')
            ->orderBy('group')
            ->orderBy('name')
            ->get(['id', 'name', 'group']);

        $grouped = [];
        foreach (PermissionCategories::CATEGORIES as $slug => $meta) {
            $grouped[] = [
                'slug'        => $slug,
                'label'       => $meta['label'],
                'color'       => $meta['color'],
                'icon'        => $meta['icon'],
                'permissions' => $permissions->where('group', $slug)->values()->map(function ($p) {
                    $labels = PermissionCategories::translate($p->name);
                    return [
                        'id'           => $p->id,
                        'name'         => $p->name,
                        'action_label' => $labels['action'],
                        'module_label' => $labels['module'],
                    ];
                }),
            ];
        }

        return response()->json([
            'status'     => 'success',
            'categories' => $grouped,
        ]);
    }

    /**
     * Compte le nombre d'utilisateurs portant un rôle donné, via la table
     * pivot directement (le Role de base Spatie n'a pas de relation users()).
     */
    private function countUsersForRole(int $roleId): int
    {
        return DB::table('model_has_roles')
            ->where('role_id', $roleId)
            ->where('model_type', \App\Models\User::class)
            ->count();
    }

    /**
     * Liste des rôles de l'établissement courant.
     * GET /api/roles
     */
    public function index(Request $request)
    {
        $establishmentId = current_establishment_id();

        $roles = Role::where('establishment_id', $establishmentId)
            ->with('permissions:id,name,group')
            ->orderBy('is_locked', 'desc')
            ->orderBy('name')
            ->get();

        $formatted = $roles->map(function ($role) {
            $groups = $role->permissions->pluck('group')->unique()->filter()->values();
            $dominantGroup = $groups->count() === 1 ? $groups->first() : ($groups->count() > 1 ? 'multi' : null);

            return [
                'id'                => $role->id,
                'name'              => $role->name,
                'slug'              => $role->slug,
                'description'       => $role->description,
                'is_system'         => (bool) $role->is_system,
                'is_locked'         => (bool) $role->is_locked,
                'users_count'       => $this->countUsersForRole($role->id),
                'permissions_count' => $role->permissions->count(),
                'category'          => $dominantGroup, // slug d'une catégorie, "multi", ou null
                'category_meta'     => $dominantGroup && $dominantGroup !== 'multi'
                    ? (PermissionCategories::CATEGORIES[$dominantGroup] ?? null)
                    : null,
                'permissions'       => $role->permissions->pluck('id'),
            ];
        });

        return response()->json([
            'status' => 'success',
            'roles'  => $formatted,
        ]);
    }

    /**
     * Création d'un rôle personnalisé, restreint à UNE SEULE catégorie
     * de permissions.
     * POST /api/roles
     */
    public function store(Request $request)
    {
        $establishmentId = current_establishment_id();

        $validated = $request->validate([
            'name'             => ['required', 'string', 'max:100'],
            'description'      => ['nullable', 'string', 'max:255'],
            'category'         => ['required', 'string', 'in:' . implode(',', array_keys(PermissionCategories::CATEGORIES))],
            'permission_ids'   => ['required', 'array', 'min:1'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ], [
            'permission_ids.required' => 'Sélectionnez au moins une permission.',
        ]);

        $permissions = Permission::whereIn('id', $validated['permission_ids'])->get();
        $invalid = $permissions->where('group', '!=', $validated['category']);

        if ($invalid->isNotEmpty()) {
            return response()->json([
                'status'  => 'error',
                'message' => "Certaines permissions sélectionnées n'appartiennent pas à la catégorie \"{$validated['category']}\". Créez un rôle distinct pour une autre catégorie.",
            ], 422);
        }

        $exists = Role::where('establishment_id', $establishmentId)
            ->where('name', $validated['name'])
            ->exists();

        if ($exists) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Un rôle porte déjà ce nom dans votre établissement.',
            ], 422);
        }

        // ─── Création via new + assignation directe des attributs ───
        // On évite Role::create([...]) car si "is_system"/"is_locked"/
        // "establishment_id" ne sont pas dans le $fillable du modèle Role,
        // la mass-assignment les ignorerait silencieusement et la colonne
        // retomberait sur sa valeur par défaut en base (souvent is_system
        // = true), cachant à tort le bouton Supprimer sur les rôles créés
        // depuis l'interface.
        $role = new Role([
            'name'       => $validated['name'],
            'guard_name' => 'web',
        ]);
        $role->establishment_id = $establishmentId;
        $role->slug             = \Illuminate\Support\Str::slug($validated['name']) . '-' . uniqid();
        $role->description      = $validated['description'] ?? null;
        $role->is_system        = false;
        $role->is_locked        = false;
        $role->save();

        $role->syncPermissions($permissions);

        return response()->json([
            'status'  => 'success',
            'message' => "Rôle \"{$role->name}\" créé avec {$permissions->count()} permission(s).",
            'role'    => $role->load('permissions'),
        ], 201);
    }

    /**
     * Modification d'un rôle existant (nom, description, permissions).
     *
     * Deux modes :
     *  - "category" fournie : rôle personnalisé classique, restreint à
     *    cette seule catégorie (comportement standard).
     *  - "category" absente/null : édition libre multi-catégories,
     *    réservée aux rôles système par défaut (Directeur, Comptable,
     *    Enseignant) qui couvrent plusieurs domaines depuis leur création
     *    par EstablishmentRoleService, avant l'existence de cette règle.
     *
     * PUT /api/roles/{id}
     */
    public function update(Request $request, string $id)
    {
        $establishmentId = current_establishment_id();

        $role = Role::where('establishment_id', $establishmentId)->findOrFail($id);

        if ($role->is_locked) {
            return response()->json([
                'status'  => 'error',
                'message' => "Ce rôle est verrouillé par le système et ne peut pas être modifié.",
            ], 403);
        }

        $validated = $request->validate([
            'name'             => ['required', 'string', 'max:100'],
            'description'      => ['nullable', 'string', 'max:255'],
            'category'         => ['nullable', 'string', 'in:' . implode(',', array_keys(PermissionCategories::CATEGORIES))],
            'permission_ids'   => ['required', 'array', 'min:1'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        $permissions = Permission::whereIn('id', $validated['permission_ids'])->get();

        // Le contrôle "une seule catégorie" ne s'applique QUE si une
        // catégorie a été explicitement fournie (édition standard).
        if (!empty($validated['category'])) {
            $invalid = $permissions->where('group', '!=', $validated['category']);
            if ($invalid->isNotEmpty()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Certaines permissions sélectionnées n'appartiennent pas à la catégorie \"{$validated['category']}\".",
                ], 422);
            }
        }

        $role->update([
            'name'        => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        $role->syncPermissions($permissions);

        return response()->json([
            'status'  => 'success',
            'message' => "Rôle \"{$role->name}\" mis à jour.",
            'role'    => $role->load('permissions'),
        ]);
    }

    /**
     * Duplique un rôle de l'établissement courant vers un AUTRE établissement
     * du même groupe scolaire — copie le nom, la description et les
     * permissions cochées, mais crée une ligne totalement INDÉPENDANTE :
     * modifier l'original par la suite n'affecte jamais la copie, et
     * inversement. La copie reste ensuite librement modifiable (cases à
     * cocher comprises) exactement comme n'importe quel autre rôle créé
     * manuellement.
     * POST /api/roles/{id}/duplicate
     */
    public function duplicate(Request $request, string $id)
    {
        $establishmentId = current_establishment_id();

        $sourceRole = Role::where('establishment_id', $establishmentId)
            ->with('permissions')
            ->findOrFail($id);

        $validated = $request->validate([
            'target_establishment_id' => ['required', 'integer', 'exists:establishments,id'],
        ]);

        $targetId = $validated['target_establishment_id'];

        if ($targetId === $establishmentId) {
            return response()->json([
                'status'  => 'error',
                'message' => "Choisissez un établissement différent de celui-ci pour dupliquer le rôle.",
            ], 422);
        }

        // ─── Sécurité : l'établissement cible doit appartenir au MÊME
        // groupe scolaire que l'établissement courant — impossible de
        // dupliquer un rôle vers un établissement complètement étranger
        // (un autre client de la plateforme, par exemple).
        $current = \App\Models\Establishment::findOrFail($establishmentId);
        $rootId  = $current->parent_establishment_id ?? $current->id;

        $targetInGroup = \App\Models\Establishment::where('id', $targetId)
            ->where(function ($q) use ($rootId) {
                $q->where('id', $rootId)->orWhere('parent_establishment_id', $rootId);
            })
            ->exists();

        if (!$targetInGroup) {
            return response()->json([
                'status'  => 'error',
                'message' => "Cet établissement ne fait pas partie du même groupe scolaire.",
            ], 403);
        }

        $exists = Role::where('establishment_id', $targetId)
            ->where('name', $sourceRole->name)
            ->exists();

        if ($exists) {
            return response()->json([
                'status'  => 'error',
                'message' => "Un rôle porte déjà ce nom dans l'établissement cible.",
            ], 422);
        }

        $newRole = new Role([
            'name'       => $sourceRole->name,
            'guard_name' => 'web',
        ]);
        $newRole->establishment_id = $targetId;
        $newRole->slug             = \Illuminate\Support\Str::slug($sourceRole->name) . '-' . uniqid();
        $newRole->description      = $sourceRole->description;
        $newRole->is_system        = false; // Toujours modifiable, même si l'original était un rôle système
        $newRole->is_locked        = false;
        $newRole->save();

        $newRole->syncPermissions($sourceRole->permissions);

        $targetEstablishment = \App\Models\Establishment::find($targetId);

        return response()->json([
            'status'  => 'success',
            'message' => "Rôle \"{$sourceRole->name}\" dupliqué avec succès vers \"{$targetEstablishment->name}\" ({$newRole->permissions->count()} permission(s) copiée(s)).",
            'role'    => $newRole->load('permissions'),
        ], 201);
    }

    /**
     * Suppression d'un rôle — interdite pour les rôles système/verrouillés.
     * Si des utilisateurs portent encore ce rôle, il leur est d'abord
     * automatiquement RÉVOQUÉ (retiré) avant la suppression définitive —
     * pas de blocage, l'admin n'a pas à réassigner manuellement au préalable.
     * DELETE /api/roles/{id}
     */
    public function destroy(string $id)
    {
        $establishmentId = current_establishment_id();

        $role = Role::where('establishment_id', $establishmentId)->findOrFail($id);

        if ($role->is_system || $role->is_locked) {
            return response()->json([
                'status'  => 'error',
                'message' => "Ce rôle système ne peut pas être supprimé.",
            ], 403);
        }

        $usersCount = $this->countUsersForRole($role->id);

        return DB::transaction(function () use ($role, $usersCount) {
            // Révoque le rôle à tous les utilisateurs qui le portent —
            // suppression directe des lignes de la table pivot, sans passer
            // par une relation "users()" qui n'existe pas sur ce modèle.
            DB::table('model_has_roles')
                ->where('role_id', $role->id)
                ->where('model_type', \App\Models\User::class)
                ->delete();

            $role->delete();

            $message = $usersCount > 0
                ? "Rôle supprimé. Il a été retiré automatiquement à {$usersCount} utilisateur(s) qui le portaient."
                : "Rôle supprimé avec succès.";

            return response()->json([
                'status'  => 'success',
                'message' => $message,
            ]);
        });
    }
}