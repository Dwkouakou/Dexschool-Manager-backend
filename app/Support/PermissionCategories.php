<?php

namespace App\Support;

/**
 * Définit les 9 catégories ("étiquettes") de permissions de l'application,
 * chacune avec sa couleur d'affichage. Un rôle personnalisé ne peut piocher
 * ses permissions QUE dans une seule catégorie à la fois — c'est cette
 * contrainte qui garde les rôles cohérents et lisibles dans une grande école.
 *
 * Utilisée à la fois :
 *  - côté backend (PermissionsSeeder pour taguer chaque permission,
 *    RoleController pour valider la cohérence à la création d'un rôle)
 *  - côté frontend (les couleurs sont renvoyées telles quelles par l'API,
 *    pas besoin de les dupliquer en React)
 */
class PermissionCategories
{
    /**
     * slug => [label affiché, couleur hex, icône Bootstrap Icons]
     */
    public const CATEGORIES = [
        'configuration' => [
            'label' => 'Configuration & Système',
            'color' => '#8C7B6B',
            'icon'  => 'bi-gear-fill',
        ],
        'scolarite' => [
            'label' => 'Scolarité',
            'color' => '#E67E22',
            'icon'  => 'bi-mortarboard-fill',
        ],
        'finances' => [
            'label' => 'Finances',
            'color' => '#27AE60',
            'icon'  => 'bi-cash-coin',
        ],
        'rh' => [
            'label' => 'Ressources Humaines',
            'color' => '#2E5FB6',
            'icon'  => 'bi-people-fill',
        ],
        'notes' => [
            'label' => 'Notes & Bulletins',
            'color' => '#9B59B6',
            'icon'  => 'bi-journal-bookmark-fill',
        ],
        'cantine' => [
            'label' => 'Cantine',
            'color' => '#F1C40F',
            'icon'  => 'bi-egg-fried',
        ],
        'transport' => [
            'label' => 'Transport',
            'color' => '#1ABC9C',
            'icon'  => 'bi-bus-front-fill',
        ],
        'bibliotheque' => [
            'label' => 'Bibliothèque',
            'color' => '#C0395A',
            'icon'  => 'bi-book-half',
        ],
        'administration' => [
            'label' => 'Administration',
            'color' => '#3D2E22',
            'icon'  => 'bi-shield-lock-fill',
        ],
    ];

    /**
     * Correspondance module de permission ({module}.{action}) → catégorie.
     * Un module donné appartient TOUJOURS à une seule catégorie.
     */
    public const MODULE_TO_CATEGORY = [
        // Configuration & Système
        'academic_years' => 'configuration',
        'periods'        => 'configuration',
        'cycles'         => 'configuration',
        'levels'         => 'configuration',
        'classes'        => 'configuration',
        'subjects'       => 'configuration',
        'settings'       => 'configuration',

        // Scolarité
        'students'    => 'scolarite',
        'enrollments' => 'scolarite',
        'documents'   => 'scolarite',

        // Finances
        'payments'          => 'finances',
        'receipts'          => 'finances',
        'financial_reports' => 'finances',

        // RH
        'employees'  => 'rh',
        'teachers'   => 'rh',
        'contracts'  => 'rh',
        'payrolls'   => 'rh',
        'positions'  => 'rh',
        'teacher_attributions' => 'rh',

        // Notes & Bulletins
        'evaluations'  => 'notes',
        'grades'       => 'notes',
        'averages'     => 'notes',
        'report_cards' => 'notes',

        // Cantine
        'canteen'               => 'cantine',
        'canteen_subscriptions' => 'cantine',
        'canteen_stock'         => 'cantine',
        'canteen_expenses'      => 'cantine',

        // Transport
        'transport'                => 'transport',
        'vehicles'                 => 'transport',
        'transport_routes'         => 'transport',
        'transport_subscriptions'  => 'transport',
        'transport_expenses'       => 'transport',

        // Bibliothèque
        'library'         => 'bibliotheque',
        'books'           => 'bibliotheque',
        'book_categories' => 'bibliotheque',
        'book_loans'      => 'bibliotheque',

        // Administration
        'users'     => 'administration',
        'roles'     => 'administration',
        'reports'   => 'administration',
        'dashboard' => 'administration',
        'activity_logs' => 'administration',
    ];

    /**
     * Traduction française du nom de module (ex: "students" → "Élèves").
     * Utilisée pour l'affichage dans l'interface de gestion des rôles.
     */
    public const MODULE_LABELS_FR = [
        'academic_years'        => 'Années scolaires',
        'periods'               => 'Périodes',
        'cycles'                => 'Cycles',
        'levels'                => 'Niveaux',
        'classes'               => 'Classes',
        'subjects'              => 'Matières',
        'settings'              => 'Paramètres',

        'students'              => 'Élèves',
        'enrollments'           => 'Inscriptions',
        'documents'             => 'Documents',

        'payments'              => 'Paiements',
        'receipts'              => 'Reçus',
        'financial_reports'     => 'Rapports financiers',

        'employees'             => 'Employés',
        'teachers'              => 'Enseignants',
        'contracts'             => 'Contrats',
        'payrolls'              => 'Bulletins de paie',
        'positions'             => 'Postes',
        'teacher_attributions'  => 'Affectations enseignants',

        'evaluations'           => 'Évaluations',
        'grades'                => 'Notes',
        'averages'              => 'Moyennes',
        'report_cards'          => 'Bulletins scolaires',

        'canteen'                => 'Cantine',
        'canteen_subscriptions'  => 'Abonnements cantine',
        'canteen_stock'          => 'Stock cantine',
        'canteen_expenses'       => 'Dépenses cantine',

        'transport'                => 'Transport',
        'vehicles'                 => 'Véhicules',
        'transport_routes'         => 'Lignes de bus',
        'transport_subscriptions'  => 'Abonnements transport',
        'transport_expenses'       => 'Dépenses transport',

        'library'          => 'Bibliothèque',
        'books'            => 'Livres',
        'book_categories'  => 'Catégories de livres',
        'book_loans'       => 'Emprunts',

        'users'     => 'Utilisateurs',
        'roles'     => 'Rôles',
        'reports'   => 'Rapports',
        'dashboard' => 'Tableau de bord',
        'activity_logs' => "Journal d'activité",
    ];

    /**
     * Traduction française de l'action (ex: "create" → "Créer").
     * Couvre toutes les actions utilisées dans PermissionsSeeder.
     */
    public const ACTION_LABELS_FR = [
        'view'             => 'Voir',
        'create'           => 'Créer',
        'edit'             => 'Modifier',
        'delete'           => 'Supprimer',
        'activate'         => 'Activer',
        'archive'          => 'Archiver',
        'generate'         => 'Générer',
        'restore'          => 'Restaurer',
        'export'           => 'Exporter',
        'import'           => 'Importer',
        'validate'         => 'Valider',
        'cancel'           => 'Annuler',
        'reenroll'         => 'Réinscrire',
        'upload'           => 'Téléverser',
        'print'            => 'Imprimer',
        'refund'           => 'Rembourser',
        'assign_classes'   => 'Affecter aux classes',
        'terminate'        => 'Résilier',
        'duplicate'        => 'Dupliquer',
        'enter'            => 'Saisir',
        'publish'          => 'Publier',
        'calculate'        => 'Calculer',
        'collect_payment'  => 'Encaisser',
        'manage'           => 'Gérer',
        'return'           => 'Retourner',
        'reset_password'   => 'Réinitialiser le mot de passe',
    ];

    /**
     * Retourne le slug de catégorie correspondant à un nom de permission
     * complet (ex: "students.create" → "scolarite").
     */
    public static function categoryForPermission(string $permissionName): ?string
    {
        $module = explode('.', $permissionName)[0] ?? null;
        return self::MODULE_TO_CATEGORY[$module] ?? null;
    }

    /**
     * Traduit un nom de permission complet en libellés français lisibles.
     * Ex: "students.create" → ['module' => 'Élèves', 'action' => 'Créer']
     * Retombe sur une version anglaise capitalisée si la traduction manque,
     * pour ne jamais rien casser si un nouveau module est ajouté sans
     * traduction immédiate.
     */
    public static function translate(string $permissionName): array
    {
        [$module, $action] = array_pad(explode('.', $permissionName), 2, null);

        return [
            'module' => self::MODULE_LABELS_FR[$module] ?? ucfirst(str_replace('_', ' ', $module ?? '')),
            'action' => self::ACTION_LABELS_FR[$action] ?? ucfirst(str_replace('_', ' ', $action ?? '')),
        ];
    }
}