<?php

use App\Http\Controllers\AcademicYearsController;
use App\Http\Controllers\AdminApiController;
use App\Http\Controllers\Api\Academic\EvaluationController;
use App\Http\Controllers\Api\Academic\ReportCardController;
use App\Http\Controllers\Api\Academic\ResultController;
use App\Http\Controllers\Api\ClasseController;
use App\Http\Controllers\Api\CycleController;
use App\Http\Controllers\Api\LevelController;
use App\Http\Controllers\CanteenController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\EstablishmentSettingsController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PeriodController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportingController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\StaffAccountController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentDocumentController;
use App\Http\Controllers\StudentImportController;
use App\Http\Controllers\StudentParentController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\SuperAdminApiController;
use App\Http\Controllers\TeacherAttributionController;
use App\Http\Controllers\TransportController;
use App\Http\Controllers\AffiliatedEstablishmentController;
use App\Http\Controllers\EstablishmentActivityLogController;
use App\Http\Controllers\PasswordResetController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


// ─── CORRECTIF SÉCURITÉ CRITIQUE ───
// Cette route était déclarée EN DEHORS de tout groupe protégé — ni
// auth:sanctum, ni permission. N'importe qui, même sans être connecté,
// pouvait exporter n'importe quel rapport. Déplacée dans le groupe
// protégé plus bas (voir section "REPORTING").


    // Route admin proteger 
    Route::middleware(['auth:sanctum', 'school.admin'])->group(function () {
        Route::put('/me/switch-viewing-year', [AdminApiController::class, 'switchViewingYear']);
        Route::get('/establishment/roles', [AdminApiController::class, 'listRoles']);
        Route::post('/establishment/users', [AdminApiController::class, 'createUser']);
        //dupliquer les classe 
        Route::get('/academic-years/{id}/duplicate-classes-preview', [ClasseController::class, 'duplicateClassesPreview']);
        Route::post('/academic-years/{id}/duplicate-classes', [ClasseController::class, 'duplicateClasses'])
            ->middleware('permission:classes.duplicate');
  
    });

    // __________________________________________________________________
    //       CONNeXION STANDARD UTILISATEURS MULTI-TENANTS 
    // __________________________________________________________________
    Route::post('/auth/login-user', [AdminApiController::class, 'loginUser']);
     Route::get('/public/transport-card/{id}/pdf', [TransportController::class, 'downloadCardPdf']);

    // ─── Réinitialisation de mot de passe par OTP ───
    // Publiques (pas de connexion requise) mais protégées par throttle :
    // max 5 tentatives par minute par IP, pour limiter les abus (spam
    // d'emails, tentatives de force brute sur le code à 6 chiffres).
    Route::post('/auth/forgot-password', [PasswordResetController::class, 'forgotPassword'])
        ->middleware('throttle:5,1');
    Route::post('/auth/reset-password', [PasswordResetController::class, 'resetPassword'])
        ->middleware('throttle:5,1');

    // ─── NOUVELLES ROUTES : Login multi-tenant (établissement + année) ───────
    // Étape 1 : Connexion avec code + login + mot de passe
    Route::post('/auth/login-establishment', [AdminApiController::class, 'loginWithEstablishment']);

    // Étape 2 : Sélection de l'année (nécessite le temp_token de l'étape 1)
    Route::post('/auth/select-year', [AdminApiController::class, 'selectYear'])
        ->middleware('auth:sanctum');

    // Étape 2 bis : Création d'une nouvelle année (nécessite aussi le temp_token)
    Route::post('/auth/create-year', [AdminApiController::class, 'createYearForEstablishment'])
        ->middleware('auth:sanctum');

    // Déconnexion — révoque le token de session
    Route::post('/auth/logout', [AdminApiController::class, 'logout'])
        ->middleware('auth:sanctum');



    // Plus tard, on ajoutera ici :
// Route::middleware('auth:sanctum')->prefix('superadmin')->group(function () {
//     Route::get('/establishments',           [SuperAdminApiController::class, 'listEstablishments']);
//     Route::post('/establishments',          [SuperAdminApiController::class, 'createEstablishment']);
//     Route::put('/establishments/{id}/toggle',[SuperAdminApiController::class, 'toggleEstablishment']);
//     // etc.
// });

    // DASHBOARD SUPERADMIN 
    Route::post("/superadmin/login", [SuperAdminApiController::class, "login"]);
    
    // ─── ROUTES SUPER ADMIN (protégées par auth + middleware SuperAdmin) ─────
    // Non touché : ce groupe est protégé par SuperAdminAuth, un système
    // entièrement séparé du système de rôles/permissions par établissement.
    Route::middleware(['auth:sanctum', \App\Http\Middleware\SuperAdminAuth::class])
    ->prefix('superadmin')
    ->group(function () {
 
        // Auth
        Route::post('/logout', [SuperAdminApiController::class, 'logout']);
 
        // Dashboard
        Route::get('/dashboard/stats', [SuperAdminApiController::class, 'dashboardStats']);
        Route::get('/logs', [SuperAdminApiController::class, 'logs']);
        Route::get('/me',          [SuperAdminApiController::class, 'me']);
        Route::put('/me',          [SuperAdminApiController::class, 'updateProfile']);
        Route::put('/me/password', [SuperAdminApiController::class, 'updatePassword']);
        // Établissements
        Route::get   ('/establishments',              [SuperAdminApiController::class, 'listEstablishments']);
        Route::post  ('/establishments',              [SuperAdminApiController::class, 'createEstablishment']);
        Route::get   ('/establishments/{id}',         [SuperAdminApiController::class, 'showEstablishment']);
        Route::put   ('/establishments/{id}',         [SuperAdminApiController::class, 'updateEstablishment']);
        Route::put   ('/establishments/{id}/toggle',  [SuperAdminApiController::class, 'toggleEstablishment']);
        Route::delete('/establishments/{id}',         [SuperAdminApiController::class, 'deleteEstablishment']);
        Route::get('/establishments/{id}/children', [SuperAdminApiController::class, 'listEstablishmentChildren']);
 
        // Collaborateurs de l'équipe DexSchool
        Route::get   ('/team',              [SuperAdminApiController::class, 'listTeamMembers']);
        Route::post  ('/team',              [SuperAdminApiController::class, 'createTeamMember']);
        Route::put   ('/team/{id}/toggle',  [SuperAdminApiController::class, 'toggleTeamMember']);
        Route::delete('/team/{id}',         [SuperAdminApiController::class, 'deleteTeamMember']);
    });
    // DASHBOARD SUPERADMIN 


   





//Gestion annees scolaire

Route::post("/save-academic-years", [AcademicYearsController::class, "AddAcdemicYears"])
    ->middleware(['auth:sanctum', 'permission:academic_years.create']);
Route::get("/academic-years", [AcademicYearsController::class, "index"])
    ->middleware(['auth:sanctum', 'permission:academic_years.view']);
Route::get('/academic-years/active', [AcademicYearsController::class, 'getActiveYear'])
    ->middleware(['auth:sanctum', 'permission:academic_years.view']);
Route::get('/academic-years/next', [AcademicYearsController::class, 'getNextYear'])
    ->middleware(['auth:sanctum', 'permission:academic_years.view']);
Route::get("/academic-years/{id}", [AcademicYearsController::class, "show"])
    ->middleware(['auth:sanctum', 'permission:academic_years.view']);
Route::put("/update-academic-years/{id}", [AcademicYearsController::class, "update"])
    ->middleware(['auth:sanctum', 'permission:academic_years.edit']);
Route::delete("/delete-academic-year/{id}", [AcademicYearsController::class, "delete"])
    ->middleware(['auth:sanctum', 'permission:academic_years.delete']);

// Activation d'année — réservée à l'Admin de l'établissement (double
// protection volontaire : school.admin ET la permission dédiée)
Route::put("/academic-years/{id}/activate", [AcademicYearsController::class, "activate"])
    ->middleware(['auth:sanctum', 'school.admin', 'permission:academic_years.activate']);

Route::middleware('auth:sanctum')->group(function () {
    
    // Journal d'activité — la vérification de permission se fait DANS le
    // contrôleur (voir EstablishmentActivityLogController::logs), pas ici,
    // pour rester cohérent avec le pattern déjà utilisé par
    // groupFinancialSummary(). Laissé tel quel volontairement.
    Route::get('/activity-logs', [EstablishmentActivityLogController::class, 'logs']);
   

    // ─── Groupe scolaire / établissements affiliés ───
    // Non touché : la logique d'autorisation est déjà entièrement gérée
    // À L'INTÉRIEUR de AffiliatedEstablishmentController (vérifications
    // is_admin, current_establishment_id() etc., corrigées plus tôt cette
    // session). Ajouter une permission générique ici ferait doublon avec
    // une logique déjà plus précise.
    Route::get('/me/establishment-group', [AffiliatedEstablishmentController::class, 'myGroup']);
    Route::post('/me/switch-establishment/{id}', [AffiliatedEstablishmentController::class, 'switchTo']);
    Route::post('/me/affiliated-establishments', [AffiliatedEstablishmentController::class, 'createChildEstablishment']);
    Route::get('/me/group-financial-summary', [AffiliatedEstablishmentController::class, 'groupFinancialSummary']);
  

    // Gestion du profile et parametres 
    // ─── CORRECTIF : GET retiré de la permission "settings.view" — cette
    // route sert aussi à récupérer le nom/logo de l'établissement pour
    // l'affichage général (en-têtes, reçus de paiement, etc.), pas
    // seulement pour la page Paramètres elle-même. La restreindre bloquait
    // n'importe quel compte sans cette permission, même pour un simple
    // affichage. Seule la MODIFICATION reste protégée.
    Route::get('/settings/establishment', [EstablishmentSettingsController::class, 'show']);
    Route::post('/settings/establishment', [EstablishmentSettingsController::class, 'update'])
        ->middleware('permission:settings.edit'); // POST — même raison que /me/profile (upload logo)

    // Profil PERSONNEL de l'utilisateur connecté — jamais de permission ici,
    // tout compte doit pouvoir consulter/modifier ses propres informations.
    Route::get('/me/profile', [ProfileController::class, 'show']);
    Route::post('/me/profile', [ProfileController::class, 'update']); // POST à cause de l'upload photo
    Route::put('/me/password', [ProfileController::class, 'updatePassword']);
    // GESTION DES PERMISSIONS — doit rester accessible à tous les comptes
    // connectés : c'est CE endpoint qui permet au frontend de savoir quels
    // boutons afficher. Le gater lui-même casserait toute l'interface.
    Route::get('/me/permissions', [ProfileController::class, 'permissions']);

 // Gestion des importation excel eleve 

    Route::post('/students/import/preview', [StudentImportController::class, 'preview'])
        ->middleware('permission:students.import');
    Route::post('/students/import', [StudentImportController::class, 'import'])
        ->middleware('permission:students.import');


    //Gestion des cycles 
    Route::post('Create-cycles', [CycleController::class, "store"])
        ->middleware('permission:cycles.create');
    Route::get('/Get-cycles', [CycleController::class, 'index'])
        ->middleware('permission:cycles.view');
    Route::get('/Show-cycle/{id}', [CycleController::class, 'show'])
        ->middleware('permission:cycles.view');
    Route::put('/Update-cycle/{id}', [CycleController::class, 'update'])
        ->middleware('permission:cycles.edit');
    Route::delete('/Delete-cycle/{id}', [CycleController::class, 'destroy'])
        ->middleware('permission:cycles.delete');

    //Gestion des Niveaux 
    Route::get('/cycles', [LevelController::class, "index"])
        ->middleware('permission:levels.view');
    Route::post('/levels', [LevelController::class, 'store'])
        ->middleware('permission:levels.create');
    Route::get('/get-levels-list', [LevelController::class, 'getLevelsList'])
        ->middleware(['auth:sanctum', 'permission:levels.view']);
    Route::put('/Update-level/{id}', [LevelController::class, 'update'])
        ->middleware(['auth:sanctum', 'permission:levels.edit']);
    Route::delete('/Delete-level/{id}', [LevelController::class, 'destroy'])
        ->middleware('permission:levels.delete');


    //Gestion des classes 
    Route::get("/years", [ClasseController::class, "index"])
        ->middleware('permission:classes.view');
    Route::get("/levelsCycle", [ClasseController::class, "levelsCycle"])
        ->middleware('permission:classes.view');
    Route::post("/Createclasses", [ClasseController::class, "store"])
        ->middleware('permission:classes.create');
    Route::get("/classes", [ClasseController::class, "getClasses"])
        ->middleware('permission:classes.view');
    Route::put('/Update-class/{id}', [ClasseController::class, 'update'])
        ->middleware('permission:classes.edit');
    Route::delete('/delete-class/{id}', [ClasseController::class, 'destroy'])
        ->middleware('permission:classes.delete');
    
    // Gestion des eleves 
    Route::post("/CreateStudents", [StudentController::class, "store"])
        ->middleware('permission:students.create');
    Route::get("/students", [StudentController::class, "index"])
        ->middleware('permission:students.view');
    Route::get('/students/search', [StudentController::class, 'search'])
        ->middleware('permission:students.view');
    Route::patch('/students/{id}/toggle-status', [StudentController::class, 'toggleStatus'])
        ->middleware('permission:students.edit');
    Route::get('/students/trashed', [StudentController::class, 'trashed'])
        ->middleware('permission:students.view');
    Route::post('/students/restore-all', [StudentController::class, 'restoreAll'])
        ->middleware('permission:students.restore');
    Route::post('/students/{id}/restore', [StudentController::class, 'restore'])
        ->middleware('permission:students.restore');
    Route::get("/students/{id}", [StudentController::class, "show"])
        ->middleware('permission:students.view');
    Route::delete('/students/{id}', [StudentController::class, 'destroy'])
        ->middleware('permission:students.delete');
    Route::put('/students/{id}/update', [StudentController::class, 'update'])
        ->middleware('permission:students.edit');

    // GESTION DES ROLES ET PERMISSIONS 
    Route::get('/roles/permissions-grouped', [RolePermissionController::class, 'permissionsGrouped'])
        ->middleware('permission:roles.view');
    Route::get('/roles', [RolePermissionController::class, 'index'])
        ->middleware('permission:roles.view');
    Route::post('/roles', [RolePermissionController::class, 'store'])
        ->middleware('permission:roles.create');
    Route::put('/roles/{id}', [RolePermissionController::class, 'update'])
        ->middleware('permission:roles.edit');
    Route::post('/roles/{id}/duplicate', [RolePermissionController::class, 'duplicate'])
        ->middleware('permission:roles.create');
    Route::delete('/roles/{id}', [RolePermissionController::class, 'destroy'])
        ->middleware('permission:roles.delete');

    // Comptes utilisateurs (StaffAccountController) — rattachés à la
    // permission "users" du catalogue (création/consultation/suppression
    // de comptes, y compris l'attribution de rôles qui en fait partie).
    Route::get('/staff/employees-without-account', [StaffAccountController::class, 'employeesWithoutAccount'])
        ->middleware('permission:users.view');
    Route::get('/staff/group-roles', [StaffAccountController::class, 'groupRoles'])
        ->middleware('permission:users.view');
    Route::get('/staff/users-with-roles', [StaffAccountController::class, 'usersWithRoles'])
        ->middleware('permission:users.view');
    Route::put('/staff/users/{id}/roles', [StaffAccountController::class, 'updateUserRoles'])
        ->middleware('permission:users.edit');
    Route::post('/staff/employees/{id}/create-account', [StaffAccountController::class, 'createAccountForEmployee'])
        ->middleware('permission:users.create');
    Route::delete('/staff/users/{id}', [StaffAccountController::class, 'destroyUserAccount'])
        ->middleware('permission:users.delete');
    
    //Gestion des parents/tuteurs d'eleves — rattaché à students.edit (fait
    // partie du dossier élève, pas de permission dédiée dans le catalogue)
    Route::post('/student-parents', [StudentParentController::class, 'store'])
        ->middleware('permission:students.edit');

    //gestion des documents 
    Route::post('/students/{student_id}/documents', [StudentDocumentController::class, 'store'])
        ->middleware('permission:documents.upload');
    Route::delete('/documents/{id}', [StudentDocumentController::class, 'destroy'])
        ->middleware('permission:documents.delete');

    // Route d'enregistrement d'une inscription / réinscription
    Route::get('/students/{id}/balance', [EnrollmentController::class, 'getStudentBalance'])
        ->middleware('permission:enrollments.view');
    Route::get('/enrollments', [EnrollmentController::class, 'index'])
        ->middleware('permission:enrollments.view');
    Route::post('/enrollments', [EnrollmentController::class, 'store'])
        ->middleware('permission:enrollments.create');
    Route::get('/enrollments/{id}/receipt', [EnrollmentController::class, 'receiptPrint'])
        ->middleware('permission:enrollments.view');
    Route::post('/enrollments/{id}/validate', [EnrollmentController::class, 'validateEnrollment'])
        ->middleware('permission:enrollments.validate');
    Route::post('/enrollments/{id}/cancel', [EnrollmentController::class, 'cancelEnrollment'])
        ->middleware('permission:enrollments.cancel');
    Route::get('/enrollments/{id}', [EnrollmentController::class, 'show'])
        ->middleware('permission:enrollments.view');
    // 2. Route pour l'ensemble des classes
    Route::get('/classesEnrollment', [ClasseController::class, 'enrollmentClasses'])
        ->middleware('permission:enrollments.view');

    //Gestion de la scolarité et des paiements 
    Route::get('/payments/receipts', [PaymentController::class, 'receiptsHistory'])
        ->middleware('permission:receipts.view');
    Route::get('/payments/student-debt/{student_id}', [PaymentController::class, 'getStudentDebt'])
        ->middleware('permission:payments.view');
    Route::post('/payments', [PaymentController::class, 'store'])
        ->middleware('permission:payments.create');
    



    // Indicateurs financiers globaux de la scolarité
    Route::get('/finance/scolarite-dashboard', [PaymentController::class, 'getScolariteMetrics'])
        ->middleware('permission:financial_reports.view');

    // Listes d'audits comptables pour le recouvrement
    Route::get('/finance/students-solde', [PaymentController::class, 'getStudentsSolded'])
        ->middleware('permission:financial_reports.view'); // Élèves à jour
    Route::get('/finance/students-dette', [PaymentController::class, 'getStudentsWithDebt'])
        ->middleware('permission:financial_reports.view'); // Élèves débiteurs




    // 1. Dossier permanent des employés
    Route::get('/employees', [EmployeeController::class, 'index'])
        ->middleware('permission:employees.view');
    Route::post('/employees', [EmployeeController::class, 'store'])
        ->middleware('permission:employees.create');
    Route::get('/employees/{id}', [EmployeeController::class, 'show'])
        ->middleware('permission:employees.view');
    Route::put('/employees/{id}', [EmployeeController::class, 'update'])
        ->middleware('permission:employees.edit');
    Route::delete('/employees/{id}', [EmployeeController::class, 'destroy'])
        ->middleware('permission:employees.delete');

    // 2. Paramétrage des métiers
    Route::get('/positions', [EmployeeController::class, 'getPositions'])
        ->middleware('permission:positions.view');

    // 3. Évolution des contrats de travail
    Route::post('/employees/{id}/contracts', [EmployeeController::class, 'addContract'])
        ->middleware('permission:contracts.create');
    Route::put('/contracts/{contract_id}/terminate', [EmployeeController::class, 'terminateContract'])
        ->middleware('permission:contracts.terminate');

    // 4. Livre des salaires et bulletins (FCFA)
    Route::get('/payrolls', [EmployeeController::class, 'payrollsHistory'])
        ->middleware('permission:payrolls.view');
    Route::post('/payrolls', [EmployeeController::class, 'generatePayroll'])
        ->middleware('permission:payrolls.generate');
    Route::get('/payrolls/receipt/{id}', [EmployeeController::class, 'printPayrollSlip'])
        ->middleware('permission:payrolls.print');



    // ─────────────────────────────────────────────────────────────────────────────
    // MODULE RESTAURATION SCOLAIRE & ÉCONOMAT (CANTINE)
    // ─────────────────────────────────────────────────────────────────────────────

    // 1. Tableau de bord principal (Accueil Cantine)
    Route::get('/canteen/dashboard', [CanteenController::class, 'dashboardMetrics'])
        ->middleware('permission:canteen.view');

    // 2. Configuration & Gestion des Forfaits / Tarifs repas — rattaché à
    // canteen_stock.manage faute de permission dédiée "meal_types" dans le
    // catalogue (à créer plus tard si besoin de plus de granularité).
    Route::get('/meal-types', [CanteenController::class, 'indexMealTypes'])
        ->middleware('permission:canteen.view');
    Route::post('/meal-types', [CanteenController::class, 'storeMealType'])
        ->middleware('permission:canteen_stock.manage');
    Route::put('/meal-types/{id}', [CanteenController::class, 'updateMealType'])
        ->middleware('permission:canteen_stock.manage');
    Route::delete('/meal-types/{id}', [CanteenController::class, 'destroyMealType'])
        ->middleware('permission:canteen_stock.manage');

    // 3. Gestion des Inscriptions & Abonnements des Rationnaires
    Route::get('/canteen-subscriptions', [CanteenController::class, 'indexSubscriptions'])
        ->middleware('permission:canteen_subscriptions.view');
    Route::post('/canteen-subscriptions', [CanteenController::class, 'storeSubscription'])
        ->middleware('permission:canteen_subscriptions.create');
    Route::get('/canteen-subscriptions/{id}', [CanteenController::class, 'showSubscription'])
        ->middleware('permission:canteen_subscriptions.view');
    Route::put('/canteen-subscriptions/{id}', [CanteenController::class, 'updateSubscription'])
        ->middleware('permission:canteen_subscriptions.edit');
    Route::delete('/canteen-subscriptions/{id}', [CanteenController::class, 'destroySubscription'])
        ->middleware('permission:canteen_subscriptions.delete');
    Route::post('/canteen-subscriptions/{id}/renew', [CanteenController::class, 'renewSubscription'])
        ->middleware('permission:canteen_subscriptions.edit');

    // 4. Guichet de Caisse Cantine (Règlement des frais de repas)
    Route::post('/canteen-subscriptions/{id}/pay', [CanteenController::class, 'collectSubscriptionPayment'])
        ->middleware('permission:canteen_subscriptions.collect_payment');

    // 5. Suivi quotidien de l'Assiduité / Feuilles d'appel du réfectoire —
    // rattaché à canteen_subscriptions.edit (l'appel modifie l'usage de
    // l'abonnement), faute de permission "attendance" dédiée.
    Route::get('/canteen-attendances', [CanteenController::class, 'indexAttendances'])
        ->middleware('permission:canteen.view');
    Route::post('/canteen-attendances', [CanteenController::class, 'storeAttendance'])
        ->middleware('permission:canteen_subscriptions.edit');

    // 6. Gestion du livre des Dépenses de la Cuisine (Marché hebdomadaire)
    Route::get('/canteen-expenses', [CanteenController::class, 'indexExpenses'])
        ->middleware('permission:canteen_expenses.view');
    Route::post('/canteen-expenses', [CanteenController::class, 'storeExpense'])
        ->middleware('permission:canteen_expenses.create');
    Route::put('/canteen-expenses/{id}', [CanteenController::class, 'updateExpense'])
        ->middleware('permission:canteen_expenses.edit');
    Route::delete('/canteen-expenses/{id}', [CanteenController::class, 'destroyExpense'])
        ->middleware('permission:canteen_expenses.delete');

    // 7. Gestion de l'Épicerie (Catalogue des denrées & Mouvements de stock)
    Route::get('/canteen/products', [CanteenController::class, 'indexProducts'])
        ->middleware('permission:canteen_stock.view');
    Route::post('/canteen/products', [CanteenController::class, 'storeProduct'])
        ->middleware('permission:canteen_stock.manage');
    Route::post('/canteen/products/movement', [CanteenController::class, 'storeStockMovement'])
        ->middleware('permission:canteen_stock.manage');
    Route::get('/canteen/products/movements', [CanteenController::class, 'indexMovements'])
        ->middleware('permission:canteen_stock.view');
    Route::get('/canteen/suppliers', [CanteenController::class, 'indexSuppliers'])
        ->middleware('permission:canteen_stock.view');
    Route::post('/canteen/suppliers', [CanteenController::class, 'storeSupplier'])
        ->middleware('permission:canteen_stock.manage');

    // ─────────────────────────────────────────────────────────────────────────────
    // MODULE TRANSPORT SCOLAIRE & PARC AUTOMOBILE
    // ─────────────────────────────────────────────────────────────────────────────

    // 1. Indicateurs du Tableau de Bord (Accueil Transport)
    Route::get('/transport/dashboard', [TransportController::class, 'dashboardMetrics'])
        ->middleware('permission:transport.view');

    // 2. Gestion du Parc Automobile (Les Bus / Cars de ramassage)
    Route::get('/vehicles', [TransportController::class, 'indexVehicles'])
        ->middleware('permission:vehicles.view');
    Route::post('/vehicles', [TransportController::class, 'storeVehicle'])
        ->middleware('permission:vehicles.create');
    Route::get('/vehicles/{id}', [TransportController::class, 'showVehicle'])
        ->middleware('permission:vehicles.view');
    Route::put('/vehicles/{id}', [TransportController::class, 'updateVehicle'])
        ->middleware('permission:vehicles.edit');
    Route::delete('/vehicles/{id}', [TransportController::class, 'destroyVehicle'])
        ->middleware('permission:vehicles.delete');

    // 3. Gestion des Chauffeurs Actifs (Liaison avec le Personnel RH)
    Route::get('/transport/available-drivers', [TransportController::class, 'getAvailableDrivers'])
        ->middleware('permission:transport.view'); // Pour remplir le select React

    // 4. Gestion des Lignes, Circuits & Arrêts (Saisie Manuelle)
    Route::get('/transport-routes', [TransportController::class, 'indexRoutes'])
        ->middleware('permission:transport_routes.view');
    Route::post('/transport-routes', [TransportController::class, 'storeRoute'])
        ->middleware('permission:transport_routes.create');
    Route::get('/transport-routes/{id}', [TransportController::class, 'showRoute'])
        ->middleware('permission:transport_routes.view');
    Route::put('/transport-routes/{id}', [TransportController::class, 'updateRoute'])
        ->middleware('permission:transport_routes.edit');
    Route::delete('/transport-routes/{id}', [TransportController::class, 'destroyRoute'])
        ->middleware('permission:transport_routes.delete');

    // 5. Gestion des Abonnements & Fichier des Élèves Transportés
    Route::get('/transport-subscriptions', [TransportController::class, 'indexSubscriptions'])
        ->middleware('permission:transport_subscriptions.view');
    Route::post('/transport-subscriptions', [TransportController::class, 'storeSubscription'])
        ->middleware('permission:transport_subscriptions.create');
    Route::get('/transport-subscriptions/{id}', [TransportController::class, 'showSubscription'])
        ->middleware('permission:transport_subscriptions.view');
    Route::put('/transport-subscriptions/{id}', [TransportController::class, 'updateSubscription'])
        ->middleware('permission:transport_subscriptions.edit');
    Route::delete('/transport-subscriptions/{id}', [TransportController::class, 'destroySubscription'])
        ->middleware('permission:transport_subscriptions.delete');

    // 6. Guichet Unique de Caisse (Encaissement des tranches de transport en FCFA)
    Route::post('/transport-subscriptions/{id}/pay', [TransportController::class, 'collectTransportPayment'])
        ->middleware('permission:transport_subscriptions.collect_payment');
        Route::post('/transport-subscriptions/{id}/renew', [TransportController::class, 'renewSubscription'])
        ->middleware('permission:transport_subscriptions.edit');
      // ─── AJOUT : historique global de tous les versements transport ───
    Route::get('/transport/payments/receipts', [TransportController::class, 'transportReceiptsHistory'])
        ->middleware('permission:transport_subscriptions.view');

     // ─── AJOUT : rapport financier Transport (résumé, rentabilité par
    // véhicule, recouvrement, évolution, impayés) — écran + PDF + Excel.
    Route::get('/transport/reports', [TransportController::class, 'financialReport'])
        ->middleware('permission:financial_reports.view');
    Route::get('/transport/reports/pdf', [TransportController::class, 'financialReportPdf'])
        ->middleware('permission:financial_reports.view');
    Route::get('/transport/reports/excel', [TransportController::class, 'financialReportExcel'])
        ->middleware('permission:financial_reports.view');

    // 7. Livre d'Audit des Charges (Carburant, Lavage, Réparation, Salaires)
    Route::get('/vehicle-expenses', [TransportController::class, 'indexExpenses'])
        ->middleware('permission:transport_expenses.view');
    Route::post('/vehicle-expenses', [TransportController::class, 'storeExpense'])
        ->middleware('permission:transport_expenses.create');
    Route::put('/vehicle-expenses/{id}', [TransportController::class, 'updateExpense'])
        ->middleware('permission:transport_expenses.edit');
    Route::delete('/vehicle-expenses/{id}', [TransportController::class, 'destroyExpense'])
        ->middleware('permission:transport_expenses.delete');



    // ─────────────────────────────────────────────────────────────────────────────
    // MODULE BIBLIOTHÈQUE & GESTION DES EMPRUNTS
    // ─────────────────────────────────────────────────────────────────────────────

    // 1. Tableau de Bord (Accueil de la Bibliothèque)
    Route::get('/library/dashboard', [LibraryController::class, 'dashboardMetrics'])
        ->middleware('permission:library.view');

    // 2. Gestion du Catalogue des Catégories
    Route::get('/book-categories', [LibraryController::class, 'indexCategories'])
        ->middleware('permission:book_categories.view');
    Route::post('/book-categories', [LibraryController::class, 'storeCategory'])
        ->middleware('permission:book_categories.create');
    Route::put('/book-categories/{id}', [LibraryController::class, 'updateCategory'])
        ->middleware('permission:book_categories.edit');
    Route::delete('/book-categories/{id}', [LibraryController::class, 'destroyCategory'])
        ->middleware('permission:book_categories.delete');

    // 3. Gestion des Fiches Livres (Œuvres globales)
    Route::get('/books', [LibraryController::class, 'indexBooks'])
        ->middleware('permission:books.view');
    Route::post('/books', [LibraryController::class, 'storeBook'])
        ->middleware('permission:books.create');
    Route::get('/books/{id}', [LibraryController::class, 'showBook'])
        ->middleware('permission:books.view');
    // Remplace ta route update par celle-ci :
    Route::match(['post', 'put'], '/books/{id}', [LibraryController::class, 'updateBook'])
        ->middleware('permission:books.edit');
    Route::delete('/books/{id}', [LibraryController::class, 'destroyBook'])
        ->middleware('permission:books.delete');

    // 4. Gestion des Exemplaires Physiques (Inventaire / Codes-barres) —
    // rattaché à books.* faute de permission "book_copies" dédiée.
    Route::get('/book-copies', [LibraryController::class, 'indexCopies'])
        ->middleware('permission:books.view');
    Route::post('/book-copies', [LibraryController::class, 'storeCopy'])
        ->middleware('permission:books.create');
    Route::put('/book-copies/{id}', [LibraryController::class, 'updateCopyStatus'])
        ->middleware('permission:books.edit'); // Pour basculer à : perdu, abîmé, disponible
    Route::delete('/book-copies/{id}', [LibraryController::class, 'destroyCopy'])
        ->middleware('permission:books.delete');

    // 5. Guichet de Saisie & Registre des Emprunts (Prêts)
    Route::get('/book-loans', [LibraryController::class, 'indexLoans'])
        ->middleware('permission:book_loans.view');
    Route::post('/book-loans', [LibraryController::class, 'storeLoan'])
        ->middleware('permission:book_loans.create'); // Émettre un prêt à un élève ou agent RH
    Route::post('/book-loans/{id}/return', [LibraryController::class, 'returnBook'])
        ->middleware('permission:book_loans.return'); // Enregistrer la restitution d'un livre

    // 6. Moteurs de recherche prédictifs pour le guichet React
    Route::get('/library/search-borrowers', [LibraryController::class, 'searchBorrowers'])
        ->middleware('permission:library.view'); // Cherche Élèves + Personnel RH combinés
    Route::get('/library/search-available-copies', [LibraryController::class, 'searchAvailableCopies'])
        ->middleware('permission:library.view'); // Cherche un exemplaire dispo par son N° d'inventaire


    

    // ─────────────────────────────────────────────────────────────────────────────
    // MODULE D'AUDIT, STRATÉGIE & RAPPORTS COMPTABLES GLOBAUX
    // ─────────────────────────────────────────────────────────────────────────────
    Route::get('/reporting/stats-hub', [ReportingController::class, 'getGlobalHubStats'])
        ->middleware('permission:reports.view');
    // {module} prendra : students, enrollments, payments, staff, canteen, transport, library

    // ─── CORRECTIF SÉCURITÉ : route déplacée ici depuis le tout début du
    // fichier, où elle n'avait AUCUNE protection (ni auth, ni permission).
    Route::get('/reporting/export/{module}', [ReportingController::class, 'exportModuleReport'])
        ->middleware('permission:reports.export');


    // Route 1 : Récupérer la liste des périodes existantes
    // Exemple d'appel React : ApiRequest('/academic/periods?academic_year_id=1')
    Route::get('/academic/periods', [PeriodController::class, 'index'])
        ->middleware('permission:periods.view');

    //  GESTION DES TRIMESTRES / SEMESTRES 

    // Route 2 : Générer automatiquement les 3 trimestres ou les 2 semestres
    // Exemple d'appel React : ApiRequest('/academic/periods/generate', { method: 'POST', body: ... })
    Route::post('/academic/periods/generate', [PeriodController::class, 'generate'])
        ->middleware('permission:periods.generate');

    // Route 3 : Activer un trimestre ou un semestre précis pour toute l'école
    // Exemple d'appel React : ApiRequest('/academic/periods/5/activate', { method: 'POST' })
    Route::post('/academic/periods/{id}/activate', [PeriodController::class, 'activate'])
        ->middleware('permission:periods.activate');
    // Nouvelle Route 4 : Mettre à jour les dates d'une période
    Route::put('/academic/periods/{id}', [PeriodController::class, 'update'])
        ->middleware('permission:periods.edit');


    // GESTION DES MATIERES 
    
    // Route 1 : Récupérer la liste de toutes les matières enregistrées
    // Exemple d'appel React : ApiRequest('/academic/subjects')
    Route::get('/academic/subjects', [SubjectController::class, 'index'])
        ->middleware('permission:subjects.view');

    // Route 2 : Créer et enregistrer une nouvelle matière (ex: Mathématiques, Français)
    // Exemple d'appel React : ApiRequest('/academic/subjects', { method: 'POST', body: ... })
    Route::post('/academic/subjects', [SubjectController::class, 'store'])
        ->middleware('permission:subjects.create');
    Route::put('/academic/subjects/{id}',    [SubjectController::class, 'update'])
        ->middleware('permission:subjects.edit');
    Route::delete('/academic/subjects/{id}', [SubjectController::class, 'destroy'])
        ->middleware('permission:subjects.delete');

    // ATTRIBUTION DES PROFS A UNE CLASSE ET MATIERE AVEC UN COEFFICIENT BIEN SPECIFIQUE 

    // Route 1 : Récupérer le personnel ayant le rôle "enseignant" (pour vos dropdowns/listes React)
    Route::get('/academic/teachers', [TeacherAttributionController::class, 'getTeachers'])
        ->middleware('permission:teachers.view');
    // REACT : ApiRequest(`/academic/teachers/${teacherId}`)
    Route::get('/academic/teachers/{id}', [TeacherAttributionController::class, 'showTeacher'])
        ->middleware('permission:teachers.view');

    // Route 2 : Enregistrer ou modifier une attribution (Associer Prof + Classe + Matière + Coefficient)
    Route::post('/academic/attributions', [TeacherAttributionController::class, 'storeAttribution'])
        ->middleware('permission:teacher_attributions.create');

    // Route 3 : Récupérer le récapitulatif des matières et coefficients d'une classe précise
    Route::get('/academic/classes/{classeId}/subjects', [TeacherAttributionController::class, 'getClassSubjects'])
        ->middleware('permission:teacher_attributions.view');
// Pour lister toutes les matières
    // ⚠️ NOTE : cette route réutilise EXACTEMENT la même URI que
    // "GET /academic/subjects" déclarée plus haut (SubjectController::index).
    // Laravel n'utilisera que la PREMIÈRE définition rencontrée — celle-ci
    // ne sera donc jamais atteinte. Pas corrigé ici (changement de routing,
    // pas de sécurité) mais à nettoyer un jour pour éviter la confusion.
    Route::get('/academic/subjects', [TeacherAttributionController::class, 'getSubjects'])
        ->middleware('permission:subjects.view');
    
    
    // REACT : ApiRequest(`/academic/teachers/${teacherId}/attributions`)
    Route::get('/academic/teachers/{id}/attributions', [TeacherAttributionController::class, 'getTeacherAttributions'])
        ->middleware('permission:teacher_attributions.view');
    Route::delete('academic/attributions/{id}', [TeacherAttributionController::class, 'destroy'])
        ->middleware('permission:teacher_attributions.delete');


    // ─────────────────────────────────────────────────────────────────────────
    // Module 4 : Gestion des Évaluations et Saisie des Notes
    // ─────────────────────────────────────────────────────────────────────────
    
    // 1. Gestion des Évaluations (Devoirs, Examens)
    Route::get('/evaluations', [EvaluationController::class, 'index'])
        ->middleware('permission:evaluations.view'); // 🌟 AJOUTÉ : Lister les évaluations
    Route::post('/evaluations', [EvaluationController::class, 'storeEvaluation'])
        ->middleware('permission:evaluations.create');
    Route::get('/evaluations/{id}', [EvaluationController::class, 'show'])
        ->middleware('permission:evaluations.view'); // 🌟 AJOUTÉ : Voir les détails d'une évaluation
    
    // 2. Gestion des Notes (Grades)
    Route::get('/evaluations/{id}/grades', [EvaluationController::class, 'getGradesStructure'])
        ->middleware('permission:grades.view'); // 🌟 AJOUTÉ : Récupérer les élèves + notes pour affichage dans le formulaire
    Route::post('/evaluations/{id}/grades', [EvaluationController::class, 'submitGrades'])
        ->middleware('permission:grades.enter');


    // _______________________________________________________________
    //    Module de calcule des moyennes 
    // _______________________________________________________________
    

    Route::post('/academic/results/calculate', [ResultController::class, 'calculateClassResults'])
        ->middleware('permission:averages.calculate');
    Route::get('/academic/results/classes/{classeId}', [ResultController::class, 'getClassResults'])
        ->middleware('permission:averages.view');
    Route::post('/academic/results/validate', [ResultController::class, 'validateClassResults'])
        ->middleware('permission:averages.validate');
    Route::get('/academic/results/student/{studentId}/details', [ResultController::class, 'getStudentSubjectDetails'])
        ->middleware('permission:averages.view');


      // Routes du Module 6 : Bulletins
    Route::get('/report-cards', [ReportCardController::class, 'index'])
        ->middleware('permission:report_cards.view');
    Route::get('/report-cards/student/{studentId}', [ReportCardController::class, 'getReportCardData'])
        ->middleware('permission:report_cards.view');

});