<?php

namespace App\Http\Controllers;

use App\Models\Academic\AcademicYears;
use App\Models\Academic\Classe;
use App\Models\Academic\Student;
use App\Models\Canteen\CanteenSubscription;
use App\Models\Library\BookLoan;
use App\Models\officeAdministration\Enrollment;
use App\Models\officeAdministration\EnrollmentFinancial;
use App\Models\officeAdministration\Payment;
use App\Models\Transport\TransportSubscription;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    //
     public function store(Request $request)
    {
        assert_writable_year();
        $validated = $request->validate([
            'enrollment_id'         => ['required', 'exists:enrollments,id'],
            'amount_paid'           => ['required', 'integer', 'min:500'], // Minimum 500 FCFA
            'payment_date'          => ['required', 'date'],
            'payment_method'        => ['required', 'in:cash,wave,orange_money,mtn_money,moov_money,virement'],
            'transaction_reference' => ['nullable', 'string', 'max:100'],
            'notes'                 => ['nullable', 'string'],
        ]);

        // 1. Récupérer le bilan financier de cette inscription
        $enrollment = Enrollment::with('financial')->findOrFail($request->enrollment_id);
        $financial = $enrollment->financial;

        // Calcul du reste à payer réel en base
        $totalDue = (int) $financial->total_due;
        $currentPaid = (int) $financial->initial_payment; // Somme déjà versée
        $resteA_Payer = $totalDue - $currentPaid;

        // Sécurité : Interdire le trop-perçu
        if ($validated['amount_paid'] > $resteA_Payer) {
            return response()->json([
                'status'  => 'error',
                'message' => "Erreur : Le montant saisi (" . number_format($validated['amount_paid'], 0, '', ' ') . " FCFA) excède le reste à payer de l'élève (" . number_format($resteA_Payer, 0, '', ' ') . " FCFA)."
            ], 422);
        }

        return DB::transaction(function () use ($validated, $enrollment, $financial) {
            // 2. Génération automatique du numéro de reçu : REC-ANNEE-COMPTEUR
            $year = date('Y');
            $lastPayment = Payment::whereRaw("receipt_number LIKE 'REC-{$year}-%'")->latest('id')->first();
            $next = $lastPayment ? ((int) substr($lastPayment->receipt_number, -5)) + 1 : 1;
            $receiptNumber = 'REC-' . $year . '-' . str_pad($next, 5, '0', STR_PAD_LEFT);

            // 3. Enregistrement du paiement en caisse
            $payment = Payment::create([
                'enrollment_id'         => $validated['enrollment_id'],
                'receipt_number'        => $receiptNumber,
                'amount_paid'           => $validated['amount_paid'],
                'payment_date'          => $validated['payment_date'],
                'payment_method'        => $validated['payment_method'],
                'transaction_reference' => $validated['transaction_reference'] ?? null,
                'notes'                 => $validated['notes'] ?? null,
                'created_by'            => Auth::id() ?? null,
            ]);

            // 4. Cumuler le versement sur la table financière de l'inscription
            $financial->increment('initial_payment', $validated['amount_paid']);

            return response()->json([
                'status'         => 'success',
                'message'        => "Encaissement enregistré avec succès sous le reçu {$receiptNumber}.",
                'receipt_number' => $receiptNumber
            ], 201);
        });
    }
    public function receiptsHistory(Request $request)
    {
        // Initialisation de la requête avec les relations requises
        $query = Payment::with(['enrollment.student', 'enrollment.classe', 'enrollment.financial']);

        // Filter 1 : Recherche textuelle (Reçu, Nom, Prénom, Matricule)
        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'LIKE', "%{$search}%")
                ->orWhereHas('enrollment.student', function ($sq) use ($search) {
                    $sq->where('first_name', 'LIKE', "%{$search}%")
                        ->orWhere('last_name', 'LIKE', "%{$search}%")
                        ->orWhere('matricule', 'LIKE', "%{$search}%");
                });
            });
        }

        // Filtre 2 : Filtrer par Classe
        if ($request->filled('class_id')) {
            $query->whereHas('enrollment', function ($q) use ($request) {
                $q->where('class_id', $request->query('class_id'));
            });
        }

        // Filtre 3 : Moyen de paiement
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->query('payment_method'));
        }

        // Filtre 4 : Plage de dates (Date début / Date fin)
        if ($request->filled('start_date')) {
            $query->whereDate('payment_date', '>=', $request->query('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('payment_date', '<=', $request->query('end_date'));
        }

        $receipts = $query->latest('payment_date')->get();

        // Formatage pour l'interface React
        $formatted = $receipts->map(function ($p) {
            $student = $p->enrollment->student;

            // Situation financière GLOBALE de l'inscription (pas juste ce
            // versement précis) — même calcul que getStudentDebt(), pour
            // que le reste à recouvrer s'affiche pareil que sur l'écran
            // d'encaissement.
            $financial = $p->enrollment->financial;
            $totalDue  = $financial ? (int) $financial->total_due : 0;
            $totalPaid = $financial ? (int) $financial->initial_payment : 0;
            $remaining = max(0, $totalDue - $totalPaid);

            return [
                'id'             => $p->id,
                'receipt_number' => $p->receipt_number,
                'amount_paid'    => (int) $p->amount_paid,
                'payment_date'   => $p->payment_date ? $p->payment_date->format('Y-m-d') : null,
                'payment_method' => $p->payment_method,
                'reference'      => $p->transaction_reference,
                'notes'          => $p->notes,
                'student_name'   => $student ? $student->first_name . ' ' . $student->last_name : 'N/A',
                'matricule'      => $student ? $student->matricule : 'N/A',
                'photo'          => $student ? $student->photo : null,
                'class_name'     => $p->enrollment->classe ? $p->enrollment->classe->name : 'N/A',

                // ─── AJOUT : situation financière globale de l'inscription ───
                'total_due'      => $totalDue,
                'remaining'      => $remaining,
            ];
        });

        return response()->json($formatted, 200);
    }

    public function getStudentDebt(string $student_id)
    {
        try {
            $student = Student::with(['classe.level', 'latestEnrollment.financial'])
                ->findOrFail($student_id);

            $enrollment = $student->latestEnrollment;
            
            if (!$enrollment) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Cet élève ne possède aucune inscription active. Impossible d’encaisser des frais.'
                ], 422);
            }

            $financial = $enrollment->financial;
            $totalDue = $financial ? (int) $financial->total_due : 0;
            $totalPaid = $financial ? (int) $financial->initial_payment : 0;
            $remaining = max(0, $totalDue - $totalPaid);

            return response()->json([
                'student_id'        => $student->id,
                'enrollment_id'     => $enrollment->id,
                'matricule'         => $student->matricule,
                'full_name'         => $student->first_name . ' ' . $student->last_name,
                'gender'            => $student->gender,
                'photo'             => $student->photo, // ← AJOUT : pour l'afficher sur le reçu d'encaissement
                'class_name'        => $student->classe ? $student->classe->name : 'N/A',
                'cycle'             => ($student->classe && $student->classe->level) ? $student->classe->level->name : 'N/A',
                'enrollment_number' => $enrollment->enrollment_number,
                'financial' => [
                    'total_due'    => $totalDue,
                    'total_paid'   => $totalPaid,
                    'remaining'    => $remaining,
                    // CORRECTION ICI : Ajout du symbole $ manquant devant totalDue
                    'pay_pct'      => $totalDue > 0 ? min(100, round(($totalPaid / $totalDue) * 100)) : 0
                ]
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Élève introuvable.'], 404);
        }
    }



    /**
     * Calcule le grand bilan financier de la scolarité globale.
     * URL : GET /api/finance/scolarite-dashboard
     */
//    public function getScolariteMetrics()
// {
//     try {
//         // 1. Récupérer l'unique année académique active actuellement
//         $activeYear = AcademicYears::where('is_active', true)->first();

//         if (!$activeYear) {
//             return response()->json([
//                 'status'  => 'error',
//                 'message' => "Impossible de charger le bilan : aucune année académique n'est marquée comme active."
//             ], 422);
//         }

//         $activeYearId = $activeYear->id;

//         // 2. Filtrer les calculs financiers pour l'année en cours (via l'inscription mère)
//         $totalAttendu = EnrollmentFinancial::whereHas('enrollment', function ($q) use ($activeYearId) {
//             $q->where('academic_year_id', $activeYearId);
//         })->sum('total_due');

//         $totalEncaisse = EnrollmentFinancial::whereHas('enrollment', function ($q) use ($activeYearId) {
//             $q->where('academic_year_id', $activeYearId);
//         })->sum('initial_payment');

//         // 3. Reste à recouvrer (Dettes parents)
//         $resteA_Recouvrer = max(0, $totalAttendu - $totalEncaisse);

//         // 4. Taux de recouvrement global de l'établissement
//         $tauxRecouvrement = $totalAttendu > 0 ? round(($totalEncaisse / $totalAttendu) * 100, 1) : 0;

//         // 5. Statistiques sur les effectifs de l'année active
//         $totalEleves = EnrollmentFinancial::whereHas('enrollment', function ($q) use ($activeYearId) {
//             $q->where('academic_year_id', $activeYearId);
//         })->count();

//         $elevesSoldes = EnrollmentFinancial::whereHas('enrollment', function ($q) use ($activeYearId) {
//             $q->where('academic_year_id', $activeYearId);
//         })->whereRaw('initial_payment >= total_due')->count();

//         $elevesDette = EnrollmentFinancial::whereHas('enrollment', function ($q) use ($activeYearId) {
//             $q->where('academic_year_id', $activeYearId);
//         })->whereRaw('initial_payment < total_due')->count();

//         // 6. Envoi de la réponse structurée à React
//         return response()->json([
//             'total_attendu'      => (int) $totalAttendu,
//             'total_encaisse'     => (int) $totalEncaisse,
//             'reste_a_recouvrer'  => (int) $resteA_Recouvrer,
//             'taux_recouvrement'  => (float) $tauxRecouvrement,
//             'total_eleves'       => (int) $totalEleves,
//             'eleves_soldes'      => (int) $elevesSoldes,
//             'eleves_dette'       => (int) $elevesDette,
//             // Retourne le nom textuel (ex: "2025-2026") lu par votre en-tête React
//             'academic_year' => $activeYear->name 
//         ], 200);

//     } catch (\Exception $e) {
//         return response()->json([
//             'status'  => 'error',
//             'message' => 'Une erreur est survenue lors du calcul des indicateurs financiers.',
//             'debug'   => $e->getMessage()
//         ], 500);
//     }
// }

// public function getScolariteMetrics()
// {
//     try {
//         $today = Carbon::today()->format('Y-m-d');
        
//         // 1. Récupérer l'unique année académique active actuellement
//         $activeYear = AcademicYears::where('is_active', true)->first();

//         if (!$activeYear) {
//             return response()->json([
//                 'status'  => 'error',
//                 'message' => "Impossible de charger le bilan : aucune année académique n'est marquée comme active."
//             ], 422);
//         }

//         $activeYearId = $activeYear->id;

//         // 2. Filtrer les calculs financiers pour l'année en cours (via l'inscription mère)
//         $totalAttendu = EnrollmentFinancial::whereHas('enrollment', function ($q) use ($activeYearId) {
//             $q->where('academic_year_id', $activeYearId);
//         })->sum('total_due');

//         $totalEncaisse = EnrollmentFinancial::whereHas('enrollment', function ($q) use ($activeYearId) {
//             $q->where('academic_year_id', $activeYearId);
//         })->sum('initial_payment');

//         // 3. Reste à recouvrer (Dettes parents)
//         $resteA_Recouvrer = max(0, $totalAttendu - $totalEncaisse);

//         // 4. Taux de recouvrement global de l'établissement
//         $tauxRecouvrement = $totalAttendu > 0 ? round(($totalEncaisse / $totalAttendu) * 100, 1) : 0;

//         // 5. Statistiques sur les effectifs de l'année active
//         $totalEleves = EnrollmentFinancial::whereHas('enrollment', function ($q) use ($activeYearId) {
//             $q->where('academic_year_id', $activeYearId);
//         })->count();

//         $elevesSoldes = EnrollmentFinancial::whereHas('enrollment', function ($q) use ($activeYearId) {
//             $q->where('academic_year_id', $activeYearId);
//         })->whereRaw('initial_payment >= total_due')->count();

//         $elevesDette = EnrollmentFinancial::whereHas('enrollment', function ($q) use ($activeYearId) {
//             $q->where('academic_year_id', $activeYearId);
//         })->whereRaw('initial_payment < total_due')->count();

//         // ─── 6. INTERCONNEXION DES NOUVEAUX MODULES (AJOUT GLOBAL) ───
        
//         // Répartition par genre (Basée sur le fichier permanent des élèves)
//         $elevesGarcons = Student::where('gender', 'M')->count();
//         $elevesFilles  = Student::where('gender', 'F')->count();
        
//         // Nombre de classes ouvertes et opérationnelles
//         $totalClasses = Classe::where('is_active', true)->count();
        
//         // Rationnaires actifs à la cantine (Abonnement valide aujourd'hui)
//         $cantineActifs = CanteenSubscription::where('status', 'active')
//             ->where('start_date', '<=', $today)
//             ->where('end_date', '>=', $today)
//             ->count();

//         // Passagers actifs dans les cars (Abonnement valide aujourd'hui)
//         $transportActifs = TransportSubscription::where('status', 'active')
//             ->where('start_date', '<=', $today)
//             ->where('end_date', '>=', $today)
//             ->count();

//         // Volumes physiques de livres hors de la bibliothèque (En cours de lecture)
//         $livresDehors = BookLoan::where('status', 'borrowed')->count();

//         // 7. Envoi de la super-réponse structurée à React
//         return response()->json([
//             'total_attendu'      => (int) $totalAttendu,
//             'total_encaisse'     => (int) $totalEncaisse,
//             'reste_a_recouvrer'  => (int) $resteA_Recouvrer,
//             'taux_recouvrement'  => (float) $tauxRecouvrement,
//             'total_eleves'       => (int) $totalEleves,
//             'eleves_soldes'      => (int) $elevesSoldes,
//             'eleves_dette'       => (int) $elevesDette,
//             'academic_year'      => $activeYear->name,
            
//             // Nouvelles clés synchronisées injectées pour le DashboardHome React :
//             'eleves_garcons'     => (int) $elevesGarcons,
//             'eleves_filles'      => (int) $elevesFilles,
//             'total_classes'      => (int) $totalClasses,
//             'cantine_actifs'     => (int) $cantineActifs,
//             'transport_actifs'   => (int) $transportActifs,
//             'livres_dehors'      => (int) $livresDehors
//         ], 200);

//     } catch (\Exception $e) {
//         return response()->json([
//             'status'  => 'error',
//             'message' => 'Une erreur est survenue lors du calcul des indicateurs financiers et globaux.',
//             'debug'   => $e->getMessage()
//         ], 500);
//     }
// }

// public function getScolariteMetrics()
// {
//     try {
//         $today = Carbon::today()->format('Y-m-d');
//         $establishmentId = current_establishment_id();

//         // 1. Année active DE CET ÉTABLISSEMENT uniquement
//         $activeYear = AcademicYears::where('establishment_id', $establishmentId)
//             ->where('is_active', true)
//             ->first();

//         if (!$activeYear) {
//             return response()->json([
//                 'status'  => 'error',
//                 'message' => "Impossible de charger le bilan : aucune année académique active pour votre établissement."
//             ], 422);
//         }

//         $activeYearId = $activeYear->id;

//         // 2. Bilan financier via l'inscription + le student (rattaché à l'établissement)
//         $financialsQuery = fn() => EnrollmentFinancial::whereHas('enrollment', function ($q) use ($activeYearId, $establishmentId) {
//             $q->where('academic_year_id', $activeYearId)
//               ->whereHas('student', fn($s) => $s->where('establishment_id', $establishmentId));
//         });

//         $totalAttendu  = (clone $financialsQuery())->sum('total_due');
//         $totalEncaisse = (clone $financialsQuery())->sum('initial_payment');

//         $resteA_Recouvrer = max(0, $totalAttendu - $totalEncaisse);
//         $tauxRecouvrement = $totalAttendu > 0 ? round(($totalEncaisse / $totalAttendu) * 100, 1) : 0;

//         $totalEleves  = (clone $financialsQuery())->count();
//         $elevesSoldes = (clone $financialsQuery())->whereRaw('initial_payment >= total_due')->count();
//         $elevesDette  = (clone $financialsQuery())->whereRaw('initial_payment < total_due')->count();

//         // 3. Compteurs globaux — filtrés AUTOMATIQUEMENT par le trait
//         $elevesGarcons = Student::where('gender', 'M')->count();
//         $elevesFilles  = Student::where('gender', 'F')->count();
//         $totalClasses  = Classe::where('is_active', true)->count();

//         $cantineActifs = CanteenSubscription::where('status', 'active')
//             ->where('start_date', '<=', $today)
//             ->where('end_date', '>=', $today)
//             ->count();

//         $transportActifs = TransportSubscription::where('status', 'active')
//             ->where('start_date', '<=', $today)
//             ->where('end_date', '>=', $today)
//             ->count();

//         $livresDehors = BookLoan::where('status', 'borrowed')->count();

//         return response()->json([
//             'total_attendu'      => (int) $totalAttendu,
//             'total_encaisse'     => (int) $totalEncaisse,
//             'reste_a_recouvrer'  => (int) $resteA_Recouvrer,
//             'taux_recouvrement'  => (float) $tauxRecouvrement,
//             'total_eleves'       => (int) $totalEleves,
//             'eleves_soldes'      => (int) $elevesSoldes,
//             'eleves_dette'       => (int) $elevesDette,
//             'academic_year'      => $activeYear->name,
//             'eleves_garcons'     => (int) $elevesGarcons,
//             'eleves_filles'      => (int) $elevesFilles,
//             'total_classes'      => (int) $totalClasses,
//             'cantine_actifs'     => (int) $cantineActifs,
//             'transport_actifs'   => (int) $transportActifs,
//             'livres_dehors'      => (int) $livresDehors,
//         ], 200);

//     } catch (\Exception $e) {
//         return response()->json([
//             'status'  => 'error',
//             'message' => 'Une erreur est survenue lors du calcul des indicateurs.',
//             'debug'   => $e->getMessage()
//         ], 500);
//     }
// }

//     public function getScolariteMetrics()
// {
//     try {
//         $today = Carbon::today()->format('Y-m-d');
//         $establishmentId = current_establishment_id();

//         // LECTURE : on affiche l'année que CE user consulte (viewing_year_id).
//         // Pour un user standard, elle est toujours égale à l'année active.
//         // Pour l'admin, elle peut être une année passée qu'il consulte volontairement.
//         $viewingYearId = current_viewing_year_id();

//         $activeYear = \App\Models\Academic\AcademicYears::where('establishment_id', $establishmentId)
//             ->where('id', $viewingYearId)
//             ->first();

//         if (!$activeYear) {
//             return response()->json([
//                 'status'  => 'error',
//                 'message' => "Aucune année scolaire à afficher pour votre session."
//             ], 422);
//         }

//         $activeYearId = $activeYear->id;
//         $isTrueActiveYear = ($activeYearId === current_active_year_id());

//         // 2. Filtrer les calculs financiers pour l'année consultée (via l'inscription mère)
//         $totalAttendu = EnrollmentFinancial::whereHas('enrollment', function ($q) use ($activeYearId, $establishmentId) {
//             $q->where('academic_year_id', $activeYearId)
//               ->whereHas('student', fn($s) => $s->where('establishment_id', $establishmentId));
//         })->sum('total_due');

//         $totalEncaisse = EnrollmentFinancial::whereHas('enrollment', function ($q) use ($activeYearId, $establishmentId) {
//             $q->where('academic_year_id', $activeYearId)
//               ->whereHas('student', fn($s) => $s->where('establishment_id', $establishmentId));
//         })->sum('initial_payment');

//         // 3. Reste à recouvrer (Dettes parents)
//         $resteA_Recouvrer = max(0, $totalAttendu - $totalEncaisse);

//         // 4. Taux de recouvrement global de l'établissement
//         $tauxRecouvrement = $totalAttendu > 0 ? round(($totalEncaisse / $totalAttendu) * 100, 1) : 0;

//         // 5. Statistiques sur les effectifs de l'année consultée
//         $totalEleves = EnrollmentFinancial::whereHas('enrollment', function ($q) use ($activeYearId, $establishmentId) {
//             $q->where('academic_year_id', $activeYearId)
//               ->whereHas('student', fn($s) => $s->where('establishment_id', $establishmentId));
//         })->count();

//         $elevesSoldes = EnrollmentFinancial::whereHas('enrollment', function ($q) use ($activeYearId, $establishmentId) {
//             $q->where('academic_year_id', $activeYearId)
//               ->whereHas('student', fn($s) => $s->where('establishment_id', $establishmentId));
//         })->whereRaw('initial_payment >= total_due')->count();

//         $elevesDette = EnrollmentFinancial::whereHas('enrollment', function ($q) use ($activeYearId, $establishmentId) {
//             $q->where('academic_year_id', $activeYearId)
//               ->whereHas('student', fn($s) => $s->where('establishment_id', $establishmentId));
//         })->whereRaw('initial_payment < total_due')->count();

//         // ─── 6. COMPTEURS GLOBAUX — filtrés AUTOMATIQUEMENT par les traits ───
//         // Student : filtré par établissement uniquement (dossier permanent, pas d'année)
//         $elevesGarcons = Student::where('gender', 'M')->count();
//         $elevesFilles  = Student::where('gender', 'F')->count();

//         // Classe : filtré par établissement ET par année (viewing_year_id via BelongsToActiveYear)
//         $totalClasses = Classe::where('is_active', true)->count();

//         // Rationnaires actifs à la cantine (Abonnement valide aujourd'hui)
//         $cantineActifs = CanteenSubscription::where('status', 'active')
//             ->where('start_date', '<=', $today)
//             ->where('end_date', '>=', $today)
//             ->count();

//         // Passagers actifs dans les cars (Abonnement valide aujourd'hui)
//         $transportActifs = TransportSubscription::where('status', 'active')
//             ->where('start_date', '<=', $today)
//             ->where('end_date', '>=', $today)
//             ->count();

//         // Volumes physiques de livres hors de la bibliothèque (En cours de lecture)
//         $livresDehors = BookLoan::where('status', 'borrowed')->count();

//         // 7. Envoi de la super-réponse structurée à React
//         return response()->json([
//             'total_attendu'      => (int) $totalAttendu,
//             'total_encaisse'     => (int) $totalEncaisse,
//             'reste_a_recouvrer'  => (int) $resteA_Recouvrer,
//             'taux_recouvrement'  => (float) $tauxRecouvrement,
//             'total_eleves'       => (int) $totalEleves,
//             'eleves_soldes'      => (int) $elevesSoldes,
//             'eleves_dette'       => (int) $elevesDette,
//             'academic_year'      => $activeYear->name,

//             'eleves_garcons'     => (int) $elevesGarcons,
//             'eleves_filles'      => (int) $elevesFilles,
//             'total_classes'      => (int) $totalClasses,
//             'cantine_actifs'     => (int) $cantineActifs,
//             'transport_actifs'   => (int) $transportActifs,
//             'livres_dehors'      => (int) $livresDehors,
//         ], 200);

//     } catch (\Exception $e) {
//         return response()->json([
//             'status'  => 'error',
//             'message' => 'Une erreur est survenue lors du calcul des indicateurs et globaux.',
//             'debug'   => $e->getMessage()
//         ], 500);
//     }
// }

    public function getScolariteMetrics()
{
    try {
        $today = Carbon::today()->format('Y-m-d');
        $establishmentId = current_establishment_id();

        // LECTURE : on affiche l'année que CE user consulte (viewing_year_id).
        // Pour un user standard, elle est toujours égale à l'année active.
        // Pour l'admin, elle peut être une année passée qu'il consulte volontairement.
        $viewingYearId = current_viewing_year_id();

        $activeYear = \App\Models\Academic\AcademicYears::where('establishment_id', $establishmentId)
            ->where('id', $viewingYearId)
            ->first();

        if (!$activeYear) {
            return response()->json([
                'status'  => 'error',
                'message' => "Aucune année scolaire à afficher pour votre session."
            ], 422);
        }

        $activeYearId = $activeYear->id;
        $isTrueActiveYear = ($activeYearId === current_active_year_id());

        // 2. Filtrer les calculs financiers pour l'année consultée (via l'inscription mère)
        $totalAttendu = EnrollmentFinancial::whereHas('enrollment', function ($q) use ($activeYearId, $establishmentId) {
            $q->where('academic_year_id', $activeYearId)
              ->whereHas('student', fn($s) => $s->where('establishment_id', $establishmentId));
        })->sum('total_due');

        $totalEncaisse = EnrollmentFinancial::whereHas('enrollment', function ($q) use ($activeYearId, $establishmentId) {
            $q->where('academic_year_id', $activeYearId)
              ->whereHas('student', fn($s) => $s->where('establishment_id', $establishmentId));
        })->sum('initial_payment');

        // 3. Reste à recouvrer (Dettes parents)
        $resteA_Recouvrer = max(0, $totalAttendu - $totalEncaisse);

        // 4. Taux de recouvrement global de l'établissement
        $tauxRecouvrement = $totalAttendu > 0 ? round(($totalEncaisse / $totalAttendu) * 100, 1) : 0;

        // 5. Statistiques sur les effectifs de l'année consultée
        $totalEleves = EnrollmentFinancial::whereHas('enrollment', function ($q) use ($activeYearId, $establishmentId) {
            $q->where('academic_year_id', $activeYearId)
              ->whereHas('student', fn($s) => $s->where('establishment_id', $establishmentId));
        })->count();

        $elevesSoldes = EnrollmentFinancial::whereHas('enrollment', function ($q) use ($activeYearId, $establishmentId) {
            $q->where('academic_year_id', $activeYearId)
              ->whereHas('student', fn($s) => $s->where('establishment_id', $establishmentId));
        })->whereRaw('initial_payment >= total_due')->count();

        $elevesDette = EnrollmentFinancial::whereHas('enrollment', function ($q) use ($activeYearId, $establishmentId) {
            $q->where('academic_year_id', $activeYearId)
              ->whereHas('student', fn($s) => $s->where('establishment_id', $establishmentId));
        })->whereRaw('initial_payment < total_due')->count();

        // ─── 6. COMPTEURS GLOBAUX — filtrés AUTOMATIQUEMENT par les traits ───
        $elevesGarcons = Student::where('gender', 'M')->count();
        $elevesFilles  = Student::where('gender', 'F')->count();

        $totalClasses = Classe::where('is_active', true)->count();

        $cantineActifs = CanteenSubscription::where('status', 'active')
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->count();

        $transportActifs = TransportSubscription::where('status', 'active')
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->count();

        $livresDehors = BookLoan::where('status', 'borrowed')->count();

        // 7. Envoi de la super-réponse structurée à React
        return response()->json([
            'total_attendu'          => (int) $totalAttendu,
            'total_encaisse'         => (int) $totalEncaisse,
            'reste_a_recouvrer'      => (int) $resteA_Recouvrer,
            'taux_recouvrement'      => (float) $tauxRecouvrement,
            'total_eleves'           => (int) $totalEleves,
            'eleves_soldes'          => (int) $elevesSoldes,
            'eleves_dette'           => (int) $elevesDette,
            'academic_year'          => $activeYear->name,
            'is_viewing_active_year' => $isTrueActiveYear,

            'eleves_garcons'     => (int) $elevesGarcons,
            'eleves_filles'      => (int) $elevesFilles,
            'total_classes'      => (int) $totalClasses,
            'cantine_actifs'     => (int) $cantineActifs,
            'transport_actifs'   => (int) $transportActifs,
            'livres_dehors'      => (int) $livresDehors,
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'status'  => 'error',
            'message' => 'Une erreur est survenue lors du calcul des indicateurs et globaux.',
            'debug'   => $e->getMessage()
        ], 500);
    }
}
    /**
     * Extrait la liste de tous les élèves ayant entièrement soldé leur scolarité.
     * URL : GET /api/finance/students-solde
     */
    public function getStudentsSolded()
    {
        $soldes = Enrollment::with(['student.classe', 'financial'])
            ->whereHas('financial', function($q) {
                $q->whereRaw('initial_payment >= total_due');
            })->get();

        return response()->json($this->formatStudentFinanceList($soldes), 200);
    }

    /**
     * Extrait le fichier des élèves en retard de paiement (Dettes).
     * URL : GET /api/finance/students-dette
     */
    public function getStudentsWithDebt()
    {
        $debiteurs = Enrollment::with(['student.classe', 'financial'])
            ->whereHas('financial', function($q) {
                $q->whereRaw('initial_payment < total_due');
            })->get();

        return response()->json($this->formatStudentFinanceList($debiteurs), 200);
    }

    /**
     * Formatage interne pour harmoniser les retours JSON
     */
    private function formatStudentFinanceList(Collection $enrollments)
    {
        return $enrollments->map(function($enr) {
            $f = $enr->financial;
            return [
                'id'            => $enr->id,
                'matricule'     => $enr->student->matricule ?? 'N/A',
                'full_name'     => ($enr->student->first_name ?? '') . ' ' . ($enr->student->last_name ?? ''),
                'class_name'    => $enr->classe->name ?? 'N/A',
                'total_due'     => (int) ($f->total_due ?? 0),
                'total_paid'    => (int) ($f->initial_payment ?? 0),
                'remaining'     => (int) max(0, ($f->total_due ?? 0) - ($f->initial_payment ?? 0)),
            ];
        });
    }

}