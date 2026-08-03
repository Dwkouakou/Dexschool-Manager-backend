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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


















// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');
    Route::get('/reporting/export/{module}', [ReportingController::class, 'exportModuleReport']); 


    // Route admin proteger 
    Route::middleware(['auth:sanctum', 'school.admin'])->group(function () {
        Route::put('/me/switch-viewing-year', [AdminApiController::class, 'switchViewingYear']);
        Route::get('/establishment/roles', [AdminApiController::class, 'listRoles']);
        Route::post('/establishment/users', [AdminApiController::class, 'createUser']);
        //dupliquer les classe 
        Route::get('/academic-years/{id}/duplicate-classes-preview', [ClasseController::class, 'duplicateClassesPreview']);
        Route::post('/academic-years/{id}/duplicate-classes', [ClasseController::class, 'duplicateClasses']);
  
    });

    // __________________________________________________________________
    //       CONNeXION STANDARD UTILISATEURS MULTI-TENANTS 
    // __________________________________________________________________
    Route::post('/auth/login-user', [AdminApiController::class, 'loginUser']);

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
    Route::middleware(['auth:sanctum', \App\Http\Middleware\SuperAdminAuth::class])
    ->prefix('superadmin')
    ->group(function () {
 
        // Auth
        Route::post('/logout', [SuperAdminApiController::class, 'logout']);
 
        // Dashboard
        Route::get('/dashboard/stats', [SuperAdminApiController::class, 'dashboardStats']);
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

Route::post("/save-academic-years", [AcademicYearsController::class, "AddAcdemicYears"])->middleware('auth:sanctum');
Route::get("/academic-years", [AcademicYearsController::class, "index"])->middleware("auth:sanctum");
Route::get('/academic-years/active', [AcademicYearsController::class, 'getActiveYear'])->middleware("auth:sanctum");
Route::get('/academic-years/next', [AcademicYearsController::class, 'getNextYear'])->middleware("auth:sanctum");
Route::get("/academic-years/{id}", [AcademicYearsController::class, "show"])->middleware("auth:sanctum");
Route::put("/update-academic-years/{id}", [AcademicYearsController::class, "update"])->middleware("auth:sanctum");
Route::delete("/delete-academic-year/{id}", [AcademicYearsController::class, "delete"])->middleware("auth:sanctum");

// Activation d'année — réservée à l'Admin de l'établissement
Route::put("/academic-years/{id}/activate", [AcademicYearsController::class, "activate"])
    ->middleware(['auth:sanctum', 'school.admin']);

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/me/establishment-group', [AffiliatedEstablishmentController::class, 'myGroup']);
    Route::post('/me/switch-establishment/{id}', [AffiliatedEstablishmentController::class, 'switchTo']);
    Route::post('/me/affiliated-establishments', [AffiliatedEstablishmentController::class, 'createChildEstablishment']);

  

    // Gestion du profile et parametres 
    Route::get('/settings/establishment', [EstablishmentSettingsController::class, 'show']);
    Route::post('/settings/establishment', [EstablishmentSettingsController::class, 'update']); // POST — même raison que /me/profile (upload logo)
    Route::get('/me/profile', [ProfileController::class, 'show']);
    Route::post('/me/profile', [ProfileController::class, 'update']); // POST à cause de l'upload photo
    Route::put('/me/password', [ProfileController::class, 'updatePassword']);
    // GESTION DES PERMISSIONS 
    Route::get('/me/permissions', [ProfileController::class, 'permissions']);

 // Gestion des importation excel eleve 

    Route::post('/students/import/preview', [StudentImportController::class, 'preview']);
    Route::post('/students/import', [StudentImportController::class, 'import']);


    //Gestion des cycles 
    Route::post('Create-cycles', [CycleController::class, "store"]);
    Route::get('/Get-cycles', [CycleController::class, 'index']);
    Route::get('/Show-cycle/{id}', [CycleController::class, 'show']);
    Route::put('/Update-cycle/{id}', [CycleController::class, 'update']);
    Route::delete('/Delete-cycle/{id}', [CycleController::class, 'destroy']);

    //Gestion des Niveaux 
    Route::get('/cycles', [LevelController::class, "index"]);
    Route::post('/levels', [LevelController::class, 'store']);
    Route::get('/get-levels-list', [LevelController::class, 'getLevelsList'])->middleware('auth:sanctum');
    Route::put('/Update-level/{id}', [LevelController::class, 'update'])->middleware('auth:sanctum');
    Route::delete('/Delete-level/{id}', [LevelController::class, 'destroy']);


    //Gestion des classes 
    Route::get("/years", [ClasseController::class, "index"]);
    Route::get("/levelsCycle", [ClasseController::class, "levelsCycle"]);
    Route::post("/Createclasses", [ClasseController::class, "store"]);
    Route::get("/classes", [ClasseController::class, "getClasses"]);
    Route::put('/Update-class/{id}', [ClasseController::class, 'update']);
    Route::delete('/delete-class/{id}', [ClasseController::class, 'destroy']);
    
    // Gestion des eleves 
    Route::post("/CreateStudents", [StudentController::class, "store"]);
    Route::get("/students", [StudentController::class, "index"]);
    Route::get('/students/search', [StudentController::class, 'search']);
    Route::patch('/students/{id}/toggle-status', [StudentController::class, 'toggleStatus']);
    Route::get('/students/trashed', [StudentController::class, 'trashed']);
    Route::post('/students/restore-all', [StudentController::class, 'restoreAll']);
    Route::post('/students/{id}/restore', [StudentController::class, 'restore']);
    Route::get("/students/{id}", [StudentController::class, "show"]);
    Route::delete('/students/{id}', [StudentController::class, 'destroy']);
    Route::put('/students/{id}/update', [StudentController::class, 'update']);

    // GESTION DES ROLES ET PERMISSIONS 
    Route::get('/roles/permissions-grouped', [RolePermissionController::class, 'permissionsGrouped']);
    Route::get('/roles', [RolePermissionController::class, 'index']);
    Route::post('/roles', [RolePermissionController::class, 'store']);
    Route::put('/roles/{id}', [RolePermissionController::class, 'update']);
    Route::delete('/roles/{id}', [RolePermissionController::class, 'destroy']);

    Route::get('/staff/employees-without-account', [StaffAccountController::class, 'employeesWithoutAccount']);
    Route::get('/staff/group-roles', [StaffAccountController::class, 'groupRoles']);
    Route::get('/staff/users-with-roles', [StaffAccountController::class, 'usersWithRoles']);
    Route::put('/staff/users/{id}/roles', [StaffAccountController::class, 'updateUserRoles']);
    Route::post('/staff/employees/{id}/create-account', [StaffAccountController::class, 'createAccountForEmployee']);
    Route::delete('/staff/users/{id}', [StaffAccountController::class, 'destroyUserAccount']);
    
    //Gestion des parents/tuteurs d'eleves 
    Route::post('/student-parents', [StudentParentController::class, 'store']);

    //gestion des documents 
    Route::post('/students/{student_id}/documents', [StudentDocumentController::class, 'store']);
    Route::delete('/documents/{id}', [StudentDocumentController::class, 'destroy']);

    // Route d'enregistrement d'une inscription / réinscription
    Route::get('/students/{id}/balance', [EnrollmentController::class, 'getStudentBalance']);
    Route::get('/enrollments', [EnrollmentController::class, 'index']);
    Route::post('/enrollments', [EnrollmentController::class, 'store']);
    Route::post('/enrollments/{id}/validate', [EnrollmentController::class, 'validateEnrollment']);
    Route::post('/enrollments/{id}/cancel', [EnrollmentController::class, 'cancelEnrollment']);
    Route::get('/enrollments/{id}', [EnrollmentController::class, 'show']);
    // 2. Route pour l'ensemble des classes
    Route::get('/classesEnrollment', [ClasseController::class, 'enrollmentClasses']);

    //Gestion de la scolarité et des paiements 
    Route::get('/payments/receipts', [PaymentController::class, 'receiptsHistory']);
    Route::get('/payments/student-debt/{student_id}', [PaymentController::class, 'getStudentDebt']);
    Route::post('/payments', [PaymentController::class, 'store']);
    



    // Indicateurs financiers globaux de la scolarité
    Route::get('/finance/scolarite-dashboard', [PaymentController::class, 'getScolariteMetrics']);

    // Listes d'audits comptables pour le recouvrement
    Route::get('/finance/students-solde', [PaymentController::class, 'getStudentsSolded']); // Élèves à jour
    Route::get('/finance/students-dette', [PaymentController::class, 'getStudentsWithDebt']); // Élèves débiteurs




    // 1. Dossier permanent des employés
    Route::get('/employees', [EmployeeController::class, 'index']);          
    Route::post('/employees', [EmployeeController::class, 'store']);         
    Route::get('/employees/{id}', [EmployeeController::class, 'show']);      
    Route::put('/employees/{id}', [EmployeeController::class, 'update']);    
    Route::delete('/employees/{id}', [EmployeeController::class, 'destroy']); 

    // 2. Paramétrage des métiers
    Route::get('/positions', [EmployeeController::class, 'getPositions']);   

    // 3. Évolution des contrats de travail
    Route::post('/employees/{id}/contracts', [EmployeeController::class, 'addContract']);       
    Route::put('/contracts/{contract_id}/terminate', [EmployeeController::class, 'terminateContract']); 

    // 4. Livre des salaires et bulletins (FCFA)
    Route::get('/payrolls', [EmployeeController::class, 'payrollsHistory']);  
    Route::post('/payrolls', [EmployeeController::class, 'generatePayroll']); 
    Route::get('/payrolls/receipt/{id}', [EmployeeController::class, 'printPayrollSlip']); 



    // ─────────────────────────────────────────────────────────────────────────────
    // MODULE RESTAURATION SCOLAIRE & ÉCONOMAT (CANTINE)
    // ─────────────────────────────────────────────────────────────────────────────

    // 1. Tableau de bord principal (Accueil Cantine)
    Route::get('/canteen/dashboard', [CanteenController::class, 'dashboardMetrics']); //ok

    // 2. Configuration & Gestion des Forfaits / Tarifs repas
    Route::get('/meal-types', [CanteenController::class, 'indexMealTypes']); // ok 
    Route::post('/meal-types', [CanteenController::class, 'storeMealType']);
    Route::put('/meal-types/{id}', [CanteenController::class, 'updateMealType']);
    Route::delete('/meal-types/{id}', [CanteenController::class, 'destroyMealType']);

    // 3. Gestion des Inscriptions & Abonnements des Rationnaires
    Route::get('/canteen-subscriptions', [CanteenController::class, 'indexSubscriptions']); // ok
    Route::post('/canteen-subscriptions', [CanteenController::class, 'storeSubscription']); //ok
    Route::get('/canteen-subscriptions/{id}', [CanteenController::class, 'showSubscription']); //ok
    Route::put('/canteen-subscriptions/{id}', [CanteenController::class, 'updateSubscription']); // ok 
    Route::delete('/canteen-subscriptions/{id}', [CanteenController::class, 'destroySubscription']); //ok
    Route::post('/canteen-subscriptions/{id}/renew', [CanteenController::class, 'renewSubscription']);

    // 4. Guichet de Caisse Cantine (Règlement des frais de repas)
    Route::post('/canteen-subscriptions/{id}/pay', [CanteenController::class, 'collectSubscriptionPayment']); // ok 

    // 5. Suivi quotidien de l'Assiduité / Feuilles d'appel du réfectoire
    Route::get('/canteen-attendances', [CanteenController::class, 'indexAttendances']); // ok           Charge les élèves à pointer pour un jour précis
    Route::post('/canteen-attendances', [CanteenController::class, 'storeAttendance']);    // ok       Sauvegarde les présences repas du jour

    // 6. Gestion du livre des Dépenses de la Cuisine (Marché hebdomadaire)
    Route::get('/canteen-expenses', [CanteenController::class, 'indexExpenses']); // ok 
    Route::post('/canteen-expenses', [CanteenController::class, 'storeExpense']); // ok 
    Route::put('/canteen-expenses/{id}', [CanteenController::class, 'updateExpense']); // ok 
    Route::delete('/canteen-expenses/{id}', [CanteenController::class, 'destroyExpense']); // ok 

    // 7. Gestion de l'Épicerie (Catalogue des denrées & Mouvements de stock)
    Route::get('/canteen/products', [CanteenController::class, 'indexProducts']); //ok 
    Route::post('/canteen/products', [CanteenController::class, 'storeProduct']); // ok
    Route::post('/canteen/products/movement', [CanteenController::class, 'storeStockMovement']); //ok 
    Route::get('/canteen/products/movements', [CanteenController::class, 'indexMovements']); // ok   // Enregistrer une entrée d'achat ou une sortie cuisine
    Route::get('/canteen/suppliers', [CanteenController::class, 'indexSuppliers']);                  // Liste des grossistes / fournisseurs
    Route::post('/canteen/suppliers', [CanteenController::class, 'storeSupplier']);                // Ajouter un nouveau fournisseur

    // ─────────────────────────────────────────────────────────────────────────────
    // MODULE TRANSPORT SCOLAIRE & PARC AUTOMOBILE
    // ─────────────────────────────────────────────────────────────────────────────

    // 1. Indicateurs du Tableau de Bord (Accueil Transport)
    Route::get('/transport/dashboard', [TransportController::class, 'dashboardMetrics']);// ok !! 

    // 2. Gestion du Parc Automobile (Les Bus / Cars de ramassage)
    Route::get('/vehicles', [TransportController::class, 'indexVehicles']); // ok !!
    Route::post('/vehicles', [TransportController::class, 'storeVehicle']); // ok 
    Route::get('/vehicles/{id}', [TransportController::class, 'showVehicle']); // ok 
    Route::put('/vehicles/{id}', [TransportController::class, 'updateVehicle']); // ok
    Route::delete('/vehicles/{id}', [TransportController::class, 'destroyVehicle']);

    // 3. Gestion des Chauffeurs Actifs (Liaison avec le Personnel RH)
    Route::get('/transport/available-drivers', [TransportController::class, 'getAvailableDrivers']); // ok  // Pour remplir le select React

    // 4. Gestion des Lignes, Circuits & Arrêts (Saisie Manuelle)
    Route::get('/transport-routes', [TransportController::class, 'indexRoutes']); // ok !! 
    Route::post('/transport-routes', [TransportController::class, 'storeRoute']); // ok  !! 
    Route::get('/transport-routes/{id}', [TransportController::class, 'showRoute']); // ok  !! 
    Route::put('/transport-routes/{id}', [TransportController::class, 'updateRoute']); // ok  !! 
    Route::delete('/transport-routes/{id}', [TransportController::class, 'destroyRoute']); // ok  !! 

    // 5. Gestion des Abonnements & Fichier des Élèves Transportés
    Route::get('/transport-subscriptions', [TransportController::class, 'indexSubscriptions']); // ok !!
    Route::post('/transport-subscriptions', [TransportController::class, 'storeSubscription']); // ok 
    Route::get('/transport-subscriptions/{id}', [TransportController::class, 'showSubscription']); // ok 
    Route::put('/transport-subscriptions/{id}', [TransportController::class, 'updateSubscription']); // ok 
    Route::delete('/transport-subscriptions/{id}', [TransportController::class, 'destroySubscription']); // ok 

    // 6. Guichet Unique de Caisse (Encaissement des tranches de transport en FCFA)
    Route::post('/transport-subscriptions/{id}/pay', [TransportController::class, 'collectTransportPayment']); // ok !!

    // 7. Livre d'Audit des Charges (Carburant, Lavage, Réparation, Salaires)
    Route::get('/vehicle-expenses', [TransportController::class, 'indexExpenses']); // ok !!
    Route::post('/vehicle-expenses', [TransportController::class, 'storeExpense']);  // ok !!
    Route::put('/vehicle-expenses/{id}', [TransportController::class, 'updateExpense']); // ok !!
    Route::delete('/vehicle-expenses/{id}', [TransportController::class, 'destroyExpense']); // ok !!



    // ─────────────────────────────────────────────────────────────────────────────
    // MODULE BIBLIOTHÈQUE & GESTION DES EMPRUNTS
    // ─────────────────────────────────────────────────────────────────────────────

    // 1. Tableau de Bord (Accueil de la Bibliothèque)
    Route::get('/library/dashboard', [LibraryController::class, 'dashboardMetrics']); // ok !!

    // 2. Gestion du Catalogue des Catégories
    Route::get('/book-categories', [LibraryController::class, 'indexCategories']); // ok !!
    Route::post('/book-categories', [LibraryController::class, 'storeCategory']); // ok  !!
    Route::put('/book-categories/{id}', [LibraryController::class, 'updateCategory']); // ok !!
    Route::delete('/book-categories/{id}', [LibraryController::class, 'destroyCategory']); // ok !!

    // 3. Gestion des Fiches Livres (Œuvres globales)
    Route::get('/books', [LibraryController::class, 'indexBooks']); // ok !! 
    Route::post('/books', [LibraryController::class, 'storeBook']); // ok !! 
    Route::get('/books/{id}', [LibraryController::class, 'showBook']); // ok !!
    // Route::post('/books/{id}', [LibraryController::class, 'updateBook']); // ok !!  // Utiliser POST avec _method=PUT si vous uploadez une image de couverture
    // 📂 routes/api.php

// Remplace ta route update par celle-ci :
Route::match(['post', 'put'], '/books/{id}', [LibraryController::class, 'updateBook']);
    Route::delete('/books/{id}', [LibraryController::class, 'destroyBook']); // ok !! 

    // 4. Gestion des Exemplaires Physiques (Inventaire / Codes-barres)
    Route::get('/book-copies', [LibraryController::class, 'indexCopies']); // ok !!
    Route::post('/book-copies', [LibraryController::class, 'storeCopy']); // ok !!
    Route::put('/book-copies/{id}', [LibraryController::class, 'updateCopyStatus']); // ok !! // Pour basculer à : perdu, abîmé, disponible
    Route::delete('/book-copies/{id}', [LibraryController::class, 'destroyCopy']); // ok !!

    // 5. Guichet de Saisie & Registre des Emprunts (Prêts)
    Route::get('/book-loans', [LibraryController::class, 'indexLoans']); // ok !!
    Route::post('/book-loans', [LibraryController::class, 'storeLoan']); // ok !!  // Émettre un prêt à un élève ou agent RH
    Route::post('/book-loans/{id}/return', [LibraryController::class, 'returnBook']); // ok  !! // Enregistrer la restitution d'un livre

    // 6. Moteurs de recherche prédictifs pour le guichet React
    Route::get('/library/search-borrowers', [LibraryController::class, 'searchBorrowers']); // ok !!  Cherche Élèves + Personnel RH combinés
    Route::get('/library/search-available-copies', [LibraryController::class, 'searchAvailableCopies']); // ok !! Cherche un exemplaire dispo par son N° d'inventaire


    

    // ─────────────────────────────────────────────────────────────────────────────
    // MODULE D'AUDIT, STRATÉGIE & RAPPORTS COMPTABLES GLOBAUX
    // ─────────────────────────────────────────────────────────────────────────────
    Route::get('/reporting/stats-hub', [ReportingController::class, 'getGlobalHubStats']);
    // {module} prendra : students, enrollments, payments, staff, canteen, transport, library


    // Route 1 : Récupérer la liste des périodes existantes
    // Exemple d'appel React : ApiRequest('/academic/periods?academic_year_id=1')
    Route::get('/academic/periods', [PeriodController::class, 'index']);

    //  GESTION DES TRIMESTRES / SEMESTRES 

    // Route 2 : Générer automatiquement les 3 trimestres ou les 2 semestres
    // Exemple d'appel React : ApiRequest('/academic/periods/generate', { method: 'POST', body: ... })
    Route::post('/academic/periods/generate', [PeriodController::class, 'generate']);

    // Route 3 : Activer un trimestre ou un semestre précis pour toute l'école
    // Exemple d'appel React : ApiRequest('/academic/periods/5/activate', { method: 'POST' })
    Route::post('/academic/periods/{id}/activate', [PeriodController::class, 'activate']);
    // Nouvelle Route 4 : Mettre à jour les dates d'une période
    Route::put('/academic/periods/{id}', [PeriodController::class, 'update']);


    // GESTION DES MATIERES 
    
    // Route 1 : Récupérer la liste de toutes les matières enregistrées
    // Exemple d'appel React : ApiRequest('/academic/subjects')
    Route::get('/academic/subjects', [SubjectController::class, 'index']);

    // Route 2 : Créer et enregistrer une nouvelle matière (ex: Mathématiques, Français)
    // Exemple d'appel React : ApiRequest('/academic/subjects', { method: 'POST', body: ... })
    Route::post('/academic/subjects', [SubjectController::class, 'store']);
    Route::put('/academic/subjects/{id}',    [SubjectController::class, 'update']);
    Route::delete('/academic/subjects/{id}', [SubjectController::class, 'destroy']);

    // ATTRIBUTION DES PROFS A UNE CLASSE ET MATIERE AVEC UN COEFFICIENT BIEN SPECIFIQUE 

    // Route 1 : Récupérer le personnel ayant le rôle "enseignant" (pour vos dropdowns/listes React)
    Route::get('/academic/teachers', [TeacherAttributionController::class, 'getTeachers']);
    // REACT : ApiRequest(`/academic/teachers/${teacherId}`)
    Route::get('/academic/teachers/{id}', [TeacherAttributionController::class, 'showTeacher']);

    // Route 2 : Enregistrer ou modifier une attribution (Associer Prof + Classe + Matière + Coefficient)
    Route::post('/academic/attributions', [TeacherAttributionController::class, 'storeAttribution']);

    // Route 3 : Récupérer le récapitulatif des matières et coefficients d'une classe précise
    Route::get('/academic/classes/{classeId}/subjects', [TeacherAttributionController::class, 'getClassSubjects']);
// Pour lister toutes les matières
    Route::get('/academic/subjects', [TeacherAttributionController::class, 'getSubjects']);
    
    
    // REACT : ApiRequest(`/academic/teachers/${teacherId}/attributions`)
    Route::get('/academic/teachers/{id}/attributions', [TeacherAttributionController::class, 'getTeacherAttributions']);
    Route::delete('academic/attributions/{id}', [TeacherAttributionController::class, 'destroy']);


    // ─────────────────────────────────────────────────────────────────────────
    // Module 4 : Gestion des Évaluations et Saisie des Notes
    // ─────────────────────────────────────────────────────────────────────────
    
    // 1. Gestion des Évaluations (Devoirs, Examens)
    Route::get('/evaluations', [EvaluationController::class, 'index']); // 🌟 AJOUTÉ : Lister les évaluations
    Route::post('/evaluations', [EvaluationController::class, 'storeEvaluation']);
    Route::get('/evaluations/{id}', [EvaluationController::class, 'show']); // 🌟 AJOUTÉ : Voir les détails d'une évaluation
    
    // 2. Gestion des Notes (Grades)
    Route::get('/evaluations/{id}/grades', [EvaluationController::class, 'getGradesStructure']); // 🌟 AJOUTÉ : Récupérer les élèves + notes pour affichage dans le formulaire
    Route::post('/evaluations/{id}/grades', [EvaluationController::class, 'submitGrades']);


    // _______________________________________________________________
    //    Module de calcule des moyennes 
    // _______________________________________________________________
    

    Route::post('/academic/results/calculate', [ResultController::class, 'calculateClassResults']);
    Route::get('/academic/results/classes/{classeId}', [ResultController::class, 'getClassResults']);
    Route::post('/academic/results/validate', [ResultController::class, 'validateClassResults']);
    Route::get('/academic/results/student/{studentId}/details', [ResultController::class, 'getStudentSubjectDetails']);


      // Routes du Module 6 : Bulletins
    Route::get('/report-cards', [ReportCardController::class, 'index']);
    Route::get('/report-cards/student/{studentId}', [ReportCardController::class, 'getReportCardData']);

});
    






  