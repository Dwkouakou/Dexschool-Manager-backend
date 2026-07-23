<?php

namespace App\Http\Controllers;

use App\Models\Academic\Classe;
use App\Models\Academic\Student;
use App\Models\Canteen\CanteenExpense;
use App\Models\Canteen\CanteenSubscription;
use App\Models\Library\BookLoan;
use App\Models\Personel\Employee;
use App\Models\Transport\TransportSubscription;
use App\Models\Transport\VehicleExpense;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportingController extends Controller
{
    //

     /**
     * Compile le bilan stratégique de tous les modules de l'établissement.
     * GET /api/reporting/stats-hub
     */
   public function getGlobalHubStats()
{
    try {
        $today = Carbon::today()->format('Y-m-d');
        $startOfMonth = Carbon::now()->startOfMonth()->format('Y-m-d 00:00:00');
        $endOfMonth = Carbon::now()->endOfMonth()->format('Y-m-d 23:59:59');

        // ── Contexte multi-tenant : établissement + année consultée ──
        $establishmentId = current_establishment_id();
        $viewingYearId    = current_viewing_year_id();

        // 1. Rapport Élèves & Structures
        $totalStudents = DB::table('students')
            ->where('establishment_id', $establishmentId)
            ->count();

        $classesCount = DB::table('classes')
            ->where('establishment_id', $establishmentId)
            ->where('academic_year_id', $viewingYearId)
            ->where('is_active', true)
            ->count();

        $avgStudentsPerClass = $classesCount > 0 ? round($totalStudents / $classesCount, 1) : 0;

        // 2. Rapport Inscriptions & Flux
        $newEnrollmentsThisMonth = DB::table('enrollments')
            ->where('establishment_id', $establishmentId)
            ->where('academic_year_id', $viewingYearId)
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->count();

        // 3. Rapport Paiements & Caisse Générale (Scolarité)
        $scolariteEncaisseeMois = DB::table('enrollment_financials')
            ->join('enrollments', 'enrollment_financials.enrollment_id', '=', 'enrollments.id')
            ->where('enrollments.establishment_id', $establishmentId)
            ->where('enrollments.academic_year_id', $viewingYearId)
            ->whereBetween('enrollment_financials.created_at', [$startOfMonth, $endOfMonth])
            ->sum('enrollment_financials.initial_payment');

        // 4. Rapport RH / Personnel
        $totalStaff = DB::table('employees')
            ->where('establishment_id', $establishmentId)
            ->where('status', 'active')
            ->count();

        $teachersCount = DB::table('employees')
            ->where('employees.establishment_id', $establishmentId)
            ->where('employees.status', 'active')
            ->where('position_id', function($q) use ($establishmentId) {
                $q->select('id')
                  ->from('positions')
                  ->where('establishment_id', $establishmentId)
                  ->where(function ($qq) {
                      $qq->where('slug', 'enseignant')
                         ->orWhere('name', 'LIKE', '%professeur%');
                  })
                  ->limit(1);
            })->count();

        // 5. Rapport Cantine (Recettes nettes du mois)
        $canteenReceipts = DB::table('canteen_subscriptions')
            ->where('establishment_id', $establishmentId)
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->sum('amount_paid');

        $canteenExpenses = DB::table('canteen_expenses')
            ->where('establishment_id', $establishmentId)
            ->whereBetween('expense_date', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()])
            ->sum('amount');

        $canteenNet = $canteenReceipts - $canteenExpenses;

        // 6. Rapport Transport (Recettes nettes du mois)
        $transportReceipts = DB::table('transport_subscriptions')
            ->where('establishment_id', $establishmentId)
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->sum('amount_paid');

        $transportExpenses = DB::table('vehicle_expenses')
            ->join('vehicles', 'vehicle_expenses.vehicle_id', '=', 'vehicles.id')
            ->where('vehicles.establishment_id', $establishmentId)
            ->whereBetween('vehicle_expenses.expense_date', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()])
            ->sum('vehicle_expenses.amount');

        $transportNet = $transportReceipts - $transportExpenses;

        // 7. Rapport Bibliothèque
        $booksDehors = DB::table('book_loans')
            ->where('establishment_id', $establishmentId)
            ->where('status', 'borrowed')
            ->count();

        $lateBooks = DB::table('book_loans')
            ->where('establishment_id', $establishmentId)
            ->where('status', 'borrowed')
            ->where('expected_return_date', '<', $today)
            ->count();

        return response()->json([
            'students' => [
                'total' => (int) $totalStudents,
                'classes_count' => (int) $classesCount,
                'average_per_class' => (float) $avgStudentsPerClass
            ],
            'enrollments' => [
                'month_count' => (int) $newEnrollmentsThisMonth,
            ],
            'payments' => [
                'collected_month' => (int) $scolariteEncaisseeMois,
            ],
            'staff' => [
                'total_active' => (int) $totalStaff,
                'teachers_count' => (int) $teachersCount
            ],
            'canteen' => [
                'net_profit' => (int) $canteenNet,
                'is_profit' => $canteenNet >= 0
            ],
            'transport' => [
                'net_profit' => (int) $transportNet,
                'is_profit' => $transportNet >= 0
            ],
            'library' => [
                'borrowed_count' => (int) $booksDehors,
                'late_count' => (int) $lateBooks
            ]
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'status'  => 'error',
            'message' => 'Une erreur technique est survenue lors de la compilation du Hub.',
            'debug'   => $e->getMessage()
        ], 500);
    }
}

    /**
     * Route universelle de génération de rapports bruts téléchargeables.
     * GET /api/reporting/export/{module}
     */
    // public function exportModuleReport(string $module)
    // {
    //     // Cette méthode sera configurée plus tard avec DomPDF ou Maatwebsite Excel.
    //     // Pour l'instant, elle confirme la bonne réception de la demande d'extraction de données.
    //     return response()->json([
    //         'status' => 'success',
    //         'message' => "Génération et compilation du rapport officiel pour le module [{$module}] initialisée. Fichier en cours d'assemblage informatique."
    //     ], 200);
    // }

     /**
     * Génère et télécharge un vrai fichier Excel/CSV structuré en temps réel.
     * GET /api/reporting/export/{module}
     */
   public function exportModuleReport(Request $request, $module)
    {
        // 1. Contrôle strict de la session de caisse transmise par l'URL React
        $token = $request->query('api_token') ?? $request->bearerToken();
        if (!$token) {
            return response()->json(['status' => 'error', 'message' => 'Accès refusé : Session de caisse invalide ou expirée.'], 401);
        }

        // ── Contexte multi-tenant : établissement + année consultée ──
        $establishmentId = current_establishment_id();
        $viewingYearId    = current_viewing_year_id();

        try {
            $fileName = 'Rapport_Decisionnel_' . $module . '_' . date('d_m_Y_Hi') . '.csv';
            $headersColumns = [];
            $csvRowsData = [];

            // 2. Branchement analytique selon le bouton cliqué sur React
            switch ($module) {
                case 'students':
                    $headersColumns = ['Matricule', 'Nom', 'Prénom', 'Genre', 'Date Naissance', 'Téléphone Parent', 'Affectation Classe', 'État Compte'];
                    $raw = DB::table('students')
                        ->leftJoin('classes', 'students.class_id', '=', 'classes.id')
                        ->where('students.establishment_id', $establishmentId)
                        ->select('students.matricule', 'students.last_name', 'students.first_name', 'students.gender', 'students.birth_date', 'students.phone', 'classes.name as class_name', 'students.is_active')
                        ->get();
                    foreach ($raw as $r) {
                        $csvRowsData[] = [$r->matricule, $r->last_name, $r->first_name, $r->gender, $r->birth_date, $r->phone, $r->class_name ?? 'Non affecté', $r->is_active ? 'Actif' : 'Inactif'];
                    }
                    break;

                case 'enrollments':
                    $headersColumns = ['N° Inscription', 'Élève', 'Classe Destination', 'Capacité Max Classe', 'Type Dossier', 'Date Entrée'];
                    $raw = DB::table('enrollments')
                        ->join('students', 'enrollments.student_id', '=', 'students.id')
                        ->join('classes', 'enrollments.class_id', '=', 'classes.id')
                        ->where('enrollments.establishment_id', $establishmentId)
                        ->where('enrollments.academic_year_id', $viewingYearId)
                        ->select('enrollments.enrollment_number', 'students.last_name', 'students.first_name', 'classes.name as class_name', 'classes.capacity', 'enrollments.type', 'enrollments.enrollment_date')
                        ->get();
                    foreach ($raw as $r) {
                        $csvRowsData[] = [$r->enrollment_number, $r->last_name . ' ' . $r->first_name, $r->class_name, $r->capacity . ' places', $r->type === 'new' ? 'Nouveau' : 'Réinscription', $r->enrollment_date];
                    }
                    break;

                case 'payments':
                    $headersColumns = ['N° Inscription', 'Nom Élève', 'Montant Dû (FCFA)', 'Total Encaissé (FCFA)', 'Arriéré / Dette (FCFA)', 'Diagnostic Recouvrement', 'Mode Paiement'];
                    $raw = DB::table('enrollment_financials')
                        ->join('enrollments', 'enrollment_financials.enrollment_id', '=', 'enrollments.id')
                        ->join('students', 'enrollments.student_id', '=', 'students.id')
                        ->where('enrollments.establishment_id', $establishmentId)
                        ->where('enrollments.academic_year_id', $viewingYearId)
                        ->select('enrollments.enrollment_number', 'students.last_name', 'students.first_name', 'enrollment_financials.total_due', 'enrollment_financials.initial_payment', 'enrollment_financials.payment_method')
                        ->get();
                    foreach ($raw as $r) {
                        $dette = (int) max(0, $r->total_due - $r->initial_payment);
                        $diagnostic = $dette === 0 ? 'Soldé' : ($r->initial_payment === 0 ? 'Impayé Total' : 'Scolarité en Reste');
                        $csvRowsData[] = [$r->enrollment_number, $r->last_name . ' ' . $r->first_name, (int)$r->total_due, (int)$r->initial_payment, $dette, $diagnostic, strtoupper($r->payment_method)];
                    }
                    break;

                case 'staff':
                    $headersColumns = ['Nom & Prénom', 'Genre', 'Téléphone', 'Email', 'Poste Occupé', 'Statut RH'];
                    $raw = DB::table('employees')
                        ->leftJoin('positions', 'employees.position_id', '=', 'positions.id')
                        ->where('employees.establishment_id', $establishmentId)
                        ->select('employees.last_name', 'employees.first_name', 'employees.gender', 'employees.phone', 'employees.email', 'positions.name as position_name', 'employees.status')
                        ->get();
                    foreach ($raw as $r) {
                        $csvRowsData[] = [$r->last_name . ' ' . $r->first_name, $r->gender, $r->phone, $r->email, $r->position_name ?? 'Agent de Soutien', $r->status === 'active' ? 'En poste' : 'Congé/Suspendu'];
                    }
                    break;

                case 'canteen':
                    $headersColumns = ['Nom Rationnaire', 'Forfait Repas', 'Échéance Carte', 'Montant Carte (FCFA)', 'Encaissé (FCFA)', 'Reste à Payer (FCFA)', 'Accès Réfectoire'];
                    $raw = DB::table('canteen_subscriptions')
                        ->join('students', 'canteen_subscriptions.student_id', '=', 'students.id')
                        ->leftJoin('meal_types', 'canteen_subscriptions.meal_type_id', '=', 'meal_types.id')
                        ->where('canteen_subscriptions.establishment_id', $establishmentId)
                        ->where('canteen_subscriptions.academic_year_id', $viewingYearId)
                        ->select('students.last_name', 'students.first_name', 'meal_types.name as meal_name', 'canteen_subscriptions.end_date', 'canteen_subscriptions.total_amount', 'canteen_subscriptions.amount_paid', 'canteen_subscriptions.status')
                        ->get();
                    foreach ($raw as $r) {
                        $dette = (int) max(0, $r->total_amount - $r->amount_paid);
                        $acces = ($r->status === 'active' && Carbon::parse($r->end_date)->isAfter(Carbon::today())) ? 'Autorisé' : 'Bloqué / Expiré';
                        $csvRowsData[] = [$r->last_name . ' ' . $r->first_name, $r->meal_name ?? 'Forfait Standard', $r->end_date, (int)$r->total_amount, (int)$r->amount_paid, $dette, $acces];
                    }
                    break;

                case 'transport':
                    $headersColumns = ['Nom Élève', 'Ligne Assignée', 'Date Échéance', 'Prix Période (FCFA)', 'Encaissé (FCFA)', 'Arriéré Transport (FCFA)', 'État Droits Bus'];
                    $raw = DB::table('transport_subscriptions')
                        ->join('students', 'transport_subscriptions.student_id', '=', 'students.id')
                        ->leftJoin('transport_routes', 'transport_subscriptions.route_id', '=', 'transport_routes.id')
                        ->where('transport_subscriptions.establishment_id', $establishmentId)
                        ->where('transport_subscriptions.academic_year_id', $viewingYearId)
                        ->select('students.last_name', 'students.first_name', 'transport_routes.name as route_name', 'transport_subscriptions.end_date', 'transport_subscriptions.total_amount', 'transport_subscriptions.amount_paid', 'transport_subscriptions.status')
                        ->get();
                    foreach ($raw as $r) {
                        $dette = (int) max(0, $r->total_amount - $r->amount_paid);
                        $droits = ($r->status === 'active' && Carbon::parse($r->end_date)->isAfter(Carbon::today())) ? 'Carte Valide' : 'Carte Périmée / Bloquée';
                        $csvRowsData[] = [$r->last_name . ' ' . $r->first_name, $r->route_name ?? 'Non spécifiée', $r->end_date, (int)$r->total_amount, (int)$r->amount_paid, $dette, $droits];
                    }
                    break;

                case 'library':
                    $headersColumns = ['Code Inventaire', 'Titre de l\'Ouvrage', 'Auteur', 'Nom Emprunteur', 'Date Sortie', 'Échéance Limite', 'Diagnostic Retard'];
                    $raw = DB::table('book_loans')
                        ->join('book_copies', 'book_loans.book_copy_id', '=', 'book_copies.id')
                        ->join('books', 'book_copies.book_id', '=', 'books.id')
                        ->leftJoin('students', 'book_loans.student_id', '=', 'students.id')
                        ->leftJoin('employees', 'book_loans.employee_id', '=', 'employees.id')
                        ->where('book_loans.establishment_id', $establishmentId)
                        ->where('book_loans.academic_year_id', $viewingYearId)
                        ->select('book_copies.inventory_number', 'books.title', 'books.author', 'students.last_name as s_last', 'students.first_name as s_first', 'employees.last_name as e_last', 'employees.first_name as e_first', 'book_loans.loan_date', 'book_loans.expected_return_date', 'book_loans.status')
                        ->get();
                    foreach ($raw as $r) {
                        $nom = $r->s_last ? ($r->s_last . ' ' . $r->s_first) : ($r->e_last . ' ' . $r->e_first . ' [Enseignant]');
                        $diagnostic = $r->status === 'returned' ? 'Rendu en Rayon' : ($r->status === 'late' ? 'HORS DÉLAIS (ALERTE)' : 'En cours de lecture');
                        $csvRowsData[] = [$r->inventory_number, $r->title, $r->author ?? '—', $nom, $r->loan_date, $r->expected_return_date, $diagnostic];
                    }
                    break;

                default:
                    return response()->json(['status' => 'error', 'message' => 'Module d\'extraction inconnu.'], 400);
            }

            // 3. Streaming binaire direct du tableau formaté au séparateur point-virgule Excel ;
            $response = new StreamedResponse(function() use ($headersColumns, $csvRowsData) {
                $file = fopen('php://output', 'w');
                
                // Forçage du BOM UTF-8 pour la prise en charge correcte des caractères spéciaux et accents
                fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
                
                fputcsv($file, $headersColumns, ';');
                foreach ($csvRowsData as $line) {
                    fputcsv($file, $line, ';');
                }
                fclose($file);
            });

            // 4. Injection des Headers HTTP pour forcer l'ouverture du gestionnaire de téléchargements du navigateur
            $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $fileName . '"');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');

            return $response;

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur imprévue a paralysé l\'assemblage du tableau Excel.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }
}