<?php

namespace App\Http\Controllers;

use App\Models\Academic\AcademicYears;
use App\Models\Canteen\CanteenAttendance;
use App\Models\Canteen\CanteenAttendanceSheet;
use App\Models\Canteen\CanteenExpense;
use App\Models\Canteen\CanteenPayment;
use App\Models\Canteen\CanteenProduct;
use App\Models\Canteen\CanteenStockMovement;
use App\Models\Canteen\CanteenSubscription;
use App\Models\Canteen\CanteenSupplier;
use App\Models\Canteen\MealType;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\CanteenReportExport;

class CanteenController extends Controller
{
    //

    /**
     * Génère un numéro de reçu séquentiel et unique pour un versement de
     * cantine — format CT-{préfixe établissement}-{année}-{numéro sur 5
     * chiffres}, ex: CT-DSM-2026-00001.
     *
     * DOIT être appelée à l'intérieur d'une DB::transaction() englobant
     * aussi l'insertion du CanteenPayment : le lockForUpdate() verrouille
     * les lignes déjà comptées jusqu'au commit, ce qui empêche deux
     * caissiers encaissant au même instant de se voir attribuer le même
     * numéro de reçu.
     */
    private function generateCanteenReceiptNumber(string $paymentDate): string
    {
        $prefix = current_establishment_prefix();
        $establishmentId = current_establishment_id();
        $year = \Illuminate\Support\Carbon::parse($paymentDate)->format('Y');

        $count = CanteenPayment::withoutGlobalScopes()
            ->where('establishment_id', $establishmentId)
            ->whereYear('payment_date', $year)
            ->lockForUpdate()
            ->count();

        $sequence = str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);

        return "CT-{$prefix}-{$year}-{$sequence}";
    }

        /**
     * Récupère les métriques globales et financières du tableau de bord de la cantine.
     * URL : GET /api/canteen/dashboard
     */
   public function dashboardMetrics()
{
    try {
        $today = Carbon::today()->format('Y-m-d');
        $startOfMonth = Carbon::now()->startOfMonth()->format('Y-m-d');
        $endOfMonth = Carbon::now()->endOfMonth()->format('Y-m-d');

        // 1. Nombre d'abonnés actifs (Inscriptions non suspendues couvrant la date du jour)
        $totalSubscribers = CanteenSubscription::where('status', 'active')
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->count();

        // 2. Repas servis aujourd'hui (Élèves cochés présents sur la feuille d'appel du jour)
        $mealsServedToday = CanteenAttendance::where('attendance_date', $today)
            ->where('present', 1)
            ->count();

        // 3. Recettes de la cantine pour le mois en cours (Total des VERSEMENTS
        // réellement encaissés ce mois-ci, en FCFA)
        // ─── CORRECTIF : mesurait auparavant les abonnements CRÉÉS ce
        // mois-ci (whereBetween sur created_at de CanteenSubscription),
        // pas les versements ENCAISSÉS ce mois-ci. Un abonnement créé en
        // juillet et soldé en septembre voyait tout son montant compté
        // sur juillet, jamais sur septembre. Maintenant basé sur le vrai
        // registre de versements CanteenPayment et sa date réelle
        // d'encaissement (payment_date).
        $monthlyReceipts = CanteenPayment::whereBetween('payment_date', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        // 4. Dépenses de marché pour le mois en cours (Achat de nourriture, gaz, charbon en FCFA)
        $monthlyExpenses = CanteenExpense::whereBetween('expense_date', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        // ── CORRECTION COMPTABLE : CALCUL DU BÉNÉFICE OU DÉFICIT NET ────────
        $monthlyReceiptsInt = (int) $monthlyReceipts;
        $monthlyExpensesInt = (int) $monthlyExpenses;
        
        // Formule standard : Recettes - Dépenses
        $netBalance = $monthlyReceiptsInt - $monthlyExpensesInt; 
        $isProfit = $netBalance >= 0;

        // ── AJOUT : alertes de stock faible ────────────────────────────────
        // Le frontend (CanteenDashboardPage.jsx) attend cette clé pour
        // afficher sa bannière d'alerte rouge, mais elle n'était jamais
        // calculée ici — la bannière ne s'affichait donc jamais, même en
        // cas de rupture réelle. On compte les denrées dont le stock
        // actuel est descendu au niveau ou en dessous de leur seuil
        // d'alerte configuré.
        $lowStockAlerts = CanteenProduct::whereColumn('current_stock', '<=', 'alert_threshold')->count();

        // ── AJOUT : taux de fréquentation du jour ────────────────────────────
        // Repas servis aujourd'hui rapporté au nombre d'abonnés actifs —
        // le state React prévoyait déjà cette clé (attendance_rate) sans
        // jamais la recevoir du backend.
        $attendanceRate = $totalSubscribers > 0
            ? (int) round(($mealsServedToday / $totalSubscribers) * 100)
            : 0;

        // 5. Envoi de la réponse structurée avec TOUTES les clés attendues par le Dashboard
        return response()->json([
            'total_subscribers'  => (int) $totalSubscribers,
            'meals_served_today' => (int) $mealsServedToday,
            'monthly_receipts'   => $monthlyReceiptsInt,
            'monthly_expenses'   => $monthlyExpensesInt,
            
            // AJOUT DES CLÉS COMPTABLES MANQUANTES POUR REACT :
            'net_balance'        => (int) $netBalance,
            'is_profit'          => (bool) $isProfit,

            // AJOUT DES CLÉS D'ALERTE / FRÉQUENTATION MANQUANTES POUR REACT :
            'low_stock_alerts'   => (int) $lowStockAlerts,
            'attendance_rate'    => $attendanceRate,
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'status'  => 'error',
            'message' => 'Erreur technique lors du calcul des indicateurs de la cantine.',
            'debug'   => $e->getMessage()
        ], 500);
    }
}


    /**
     * Récupère le catalogue complet des forfaits et tarifs de repas de la cantine.
     * URL : GET /api/meal-types
     */
    public function indexMealTypes()
    {
        try {
            // Extraction de tous les forfaits configurés (actifs et inactifs), triés par ordre alphabétique
            $mealTypes = MealType::orderBy('name', 'asc')->get();

            // Reformatage propre des structures pour sécuriser les types monétaires en Franc CFA
            $formatted = $mealTypes->map(function ($meal) {
                return [
                    'id'               => $meal->id,
                    'name'             => $meal->name, // ex: Ration Complète Mensuelle
                    'code'             => $meal->code, // ex: RAT-MEN
                    'description'      => $meal->description,
                    'price_per_month'  => (int) $meal->price_per_month, // Forçage en entier pour le FCFA
                    'is_active'        => (bool) $meal->is_active,
                ];
            });

            return response()->json($formatted, 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la récupération des forfaits repas.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Enregistre un nouveau forfait / type de repas dans le système.
     * URL : POST /api/meal-types
     */
    public function storeMealType(Request $request)
    {
        assert_writable_year(); // ─── AJOUT : bloque en consultation d'année archivée, comme le reste du module
        // 1. Validation stricte du forfait (Prix obligatoirement entier positif pour le FCFA)
        $validated = $request->validate([
            'name'            => ['required', 'string', 'max:100'],
            'code'            => ['required', 'string', 'max:20', 'unique:meal_types,code'],
            'description'     => ['nullable', 'string'],
            'price_per_month' => ['required', 'integer', 'min:0'], // Tarif de base en FCFA
        ]);

        try {
            // 2. Standardisation du code forfaitaire en lettres majuscules (ex: rat-men -> RAT-MEN)
            $validated['code'] = strtoupper($validated['code']);
            $validated['is_active'] = true; // Actif par défaut à la création

            // 3. Enregistrement en base de données
            $mealType = MealType::create($validated);

            return response()->json([
                'status'    => 'success',
                'message'   => "Le forfait repas '{$mealType->name}' a été configuré avec succès au tarif de " . number_format($mealType->price_per_month, 0, '', ' ') . " FCFA.",
                'meal_type' => $mealType
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur de validation. Ce code de forfait est peut-être déjà utilisé.',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur imprévue est survenue lors de la création du forfait.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Modifie les informations ou le tarif d'un forfait repas existant.
     * URL : PUT /api/meal-types/{id}
     */
    public function updateMealType(Request $request, string $id)
    {
        assert_writable_year(); // ─── AJOUT : bloque en consultation d'année archivée
        try {
            $mealType = MealType::findOrFail($id);

            // 1. Validation stricte des modifications (Ignorer le code actuel de ce forfait)
            $validated = $request->validate([
                'name'            => ['required', 'string', 'max:100'],
                'code'            => ['required', 'string', 'max:20', Rule::unique('meal_types', 'code')->ignore($id)],
                'description'     => ['nullable', 'string'],
                'price_per_month' => ['required', 'integer', 'min:0'], // Nouveau tarif en FCFA
                'is_active'       => ['required', 'boolean'],
            ]);

            // 2. Standardisation du code en majuscules
            $validated['code'] = strtoupper($validated['code']);

            // 3. Application de la mise à jour en base de données
            $mealType->update($validated);

            return response()->json([
                'status'  => 'success',
                'message' => "Le forfait '{$mealType->name}' a été mis à jour avec succès."
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur de validation. Vérifiez que le code saisi n’est pas déjà utilisé.',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Forfait repas introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur imprévue est survenue lors de la mise à jour du forfait.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supprime un forfait repas s'il n'est lié à aucun historique.
     * URL : DELETE /api/meal-types/{id}
     */
    public function destroyMealType(string $id)
    {
        assert_writable_year(); // ─── AJOUT : bloque en consultation d'année archivée
        try {
            $mealType = MealType::withCount('subscriptions')->findOrFail($id);

            // 1. VERROU DE SÉCURITÉ COMPTABLE : Interdire la suppression si des élèves y sont abonnés
            if ($mealType->subscriptions_count > 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Impossible de supprimer ce forfait car " . $mealType->subscriptions_count . " élève(s) y sont actuellement inscrit(s). Veuillez plutôt le désactiver en décochant la case 'Actif'."
                ], 422);
            }

            // 2. Suppression physique si la table est libre de toute attache
            $mealType->delete();

            return response()->json([
                'status'  => 'success',
                'message' => 'Le forfait repas a été retiré du catalogue avec succès.'
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Forfait repas introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la suppression du forfait.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }



    /**
     * Liste globale de tous les abonnements cantine avec les profils élèves et classes.
     * URL : GET /api/canteen-subscriptions
     */
    public function indexSubscriptions()
    {
        try {
            // 1. Chargement des abonnements avec les relations imbriquées (élève et sa classe)
            $subscriptions = CanteenSubscription::with(['student.classe', 'mealType'])
                ->latest()
                ->get();

            // 2. Reformatage adaptatif pour simplifier la lecture des clés imbriquées sur React
            $formatted = $subscriptions->map(function ($sub) {
                $student = $sub->student;
                
                return [
                    'id'             => $sub->id,
                    'start_date'     => $sub->start_date ? $sub->start_date->format('Y-m-d') : null,
                    'end_date'       => $sub->end_date ? $sub->end_date->format('Y-m-d') : null,
                    'status'         => $sub->status,          // active, inactive, suspended
                    'payment_status' => $sub->payment_status,   // unpaid, partial, paid
                    'notes'          => $sub->notes,
                    
                    // Montants financiers stricts convertis en entiers pour le FCFA
                    'total_amount'   => (int) $sub->total_amount,
                    'amount_paid'    => (int) $sub->amount_paid,
                    'remaining'      => (int) max(0, $sub->total_amount - $sub->amount_paid),

                    // Informations de l'élève rationnaire
                    'student' => $student ? [
                        'id'         => $student->id,
                        'matricule'  => $student->matricule,
                        'first_name' => $student->first_name,
                        'last_name'  => $student->last_name,
                        'gender'     => $student->gender,
                    ] : null,

                    // Nom de la classe extrait de manière sécurisée depuis la relation de l'élève
                    'class_name' => ($student && $student->classe) ? $student->classe->name : 'Non affecté',

                    // Détails du forfait repas lié
                    'meal_type' => $sub->mealType ? [
                        'id'   => $sub->mealType->id,
                        'name' => $sub->mealType->name,
                        'code' => $sub->mealType->code,
                    ] : null,
                ];
            });

            return response()->json($formatted, 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la récupération des abonnements de cantine.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Enregistre un nouvel abonnement de cantine pour un élève avec son acompte initial.
     * URL : POST /api/canteen-subscriptions
     */
    public function storeSubscription(Request $request)
    {
        assert_writable_year();
        // 1. Validation stricte des données d'abonnement et des montants en FCFA (entiers)
        $validated = $request->validate([
            'student_id'   => ['required', 'exists:students,id'],
            'meal_type_id' => ['required', 'exists:meal_types,id'],
            'start_date'   => ['required', 'date'],
            'end_date'     => ['required', 'date', 'after_or_equal:start_date'],
            'total_amount' => ['required', 'integer', 'min:0'],
            'amount_paid'  => ['required', 'integer', 'min:0'],
            'notes'        => ['nullable', 'string'],
        ]);

        try {
            // 2. Récupérer l'année académique active automatiquement
            $activeYearId = current_active_year_id();

            if (!$activeYearId) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Opération impossible : Aucune année académique n'est active actuellement."
                ], 422);
            }

            // 3. SÉCURITÉ ANTI-DOUBLON : Un élève ne peut avoir qu'un seul abonnement par année scolaire
            $exists = CanteenSubscription::where('student_id', $validated['student_id'])
                ->where('academic_year_id', $activeYearId)
                ->exists();

            if ($exists) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Cet élève possède déjà une fiche d'abonnement active à la cantine pour cette année scolaire."
                ], 422);
            }

            // 4. Déterminer automatiquement le statut du paiement en FCFA
            $total = (int) $validated['total_amount'];
            $paid  = (int) $validated['amount_paid'];

            if ($paid >= $total) {
                $paymentStatus = 'paid';    // Entièrement soldé
            } elseif ($paid > 0 && $paid < $total) {
                $paymentStatus = 'partial'; // Acompte / Avance versée
            } else {
                $paymentStatus = 'unpaid';  // Aucun versement de départ
            }

            // 5. Insertion en base de données, avec traçabilité de l'acompte
            // initial dans le registre de versements si un montant a été
            // encaissé dès la création (sinon cet acompte n'apparaissait
            // jamais dans le Cumul ni dans l'historique des reçus, seul
            // amount_paid était mis à jour — c'est exactement l'écart
            // observé entre le total payé affiché et le cumul détaillé).
            return DB::transaction(function () use ($validated, $activeYearId, $total, $paid, $paymentStatus) {
                $subscription = CanteenSubscription::create([
                    'student_id'       => $validated['student_id'],
                    'academic_year_id' => $activeYearId,
                    'meal_type_id'     => $validated['meal_type_id'],
                    'start_date'       => $validated['start_date'],
                    'end_date'         => $validated['end_date'],
                    // ─── AJOUT : initialisée à start_date à la création,
                    // sera mise à jour à chaque renouvellement pour
                    // toujours refléter le début de la période EN COURS
                    'current_period_start' => $validated['start_date'],
                    'total_amount'     => $total,
                    'amount_paid'      => $paid,
                    'status'           => 'active', // Actif d'office à la création
                    'payment_status'   => $paymentStatus,
                    'notes'            => $validated['notes'] ?? null,
                ]);

                if ($paid > 0) {
                    $paymentDate = now()->format('Y-m-d');
                    $receiptNumber = $this->generateCanteenReceiptNumber($paymentDate);

                    $payment = CanteenPayment::create([
                        'canteen_subscription_id' => $subscription->id,
                        'amount'                  => $paid,
                        // ─── AJOUT : reste à payer figé juste après cet
                        // acompte initial (0 si l'abonnement est soldé
                        // dès la création)
                        'remaining_after'         => max(0, $total - $paid),
                        'payment_date'            => $paymentDate,
                        // ─── AJOUT : l'acompte initial couvre toujours la
                        // période de l'abonnement telle que définie à la
                        // création (start_date → end_date), pas la date
                        // d'encaissement
                        'period_covered'          => $validated['start_date'],
                        'period_end'              => $validated['end_date'],
                        'type'                    => 'payment',
                        'receipt_number'          => $receiptNumber,
                        'collected_by'            => Auth::id() ?? null,
                    ]);
                }

                return response()->json([
                    'status'  => 'success',
                    'message' => "L'élève a été inscrit avec succès au service de restauration.",
                    'subscription' => $subscription,
                    // ─── AJOUT : permet au frontend de proposer
                    // l'impression immédiate du reçu, comme pour un
                    // versement classique ou un renouvellement ───
                    'receipt_id'     => $payment->id ?? null,
                    'receipt_number' => $payment->receipt_number ?? null,
                ], 201);
            });

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => "Une erreur est survenue lors de l'affectation du dossier de cantine.",
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Affiche les détails complets d'un abonnement de cantine unique avec ses historiques de présence.
     * URL : GET /api/canteen-subscriptions/{id}
     */
    public function showSubscription(string $id)
    {
        try {
            // 1. Recherche de la souscription avec toutes ses relations et son historique de pointage
            $subscription = CanteenSubscription::with([
                'student.classe', 
                'mealType', 
                'attendances' => function ($query) {
                    $query->orderBy('attendance_date', 'desc'); // Du repas le plus récent au plus ancien
                },
                // ─── AJOUT : historique des versements/renouvellements
                // pour alimenter le bouton "Cumul" du tableau ───
                'payments' => function ($query) {
                    $query->orderBy('payment_date', 'desc');
                }
            ])->findOrFail($id);

            $student = $subscription->student;

            // 2. Reformatage des blocs de données pour l'interface React
            $formatted = [
                'id'             => $subscription->id,
                'start_date'     => $subscription->start_date ? $subscription->start_date->format('Y-m-d') : null,
                'end_date'       => $subscription->end_date ? $subscription->end_date->format('Y-m-d') : null,
                'status'         => $subscription->status,          // active, inactive, suspended
                'payment_status' => $subscription->payment_status,   // unpaid, partial, paid
                'notes'          => $subscription->notes,
                
                // Comptabilité analytique en Franc CFA (Entiers)
                'total_amount'   => (int) $subscription->total_amount,
                'amount_paid'    => (int) $subscription->amount_paid,
                'remaining'      => (int) max(0, $subscription->total_amount - $subscription->amount_paid),

                // Profil complet de l'élève rationnaire
                'student' => $student ? [
                    'id'          => $student->id,
                    'matricule'   => $student->matricule,
                    'first_name'  => $student->first_name,
                    'last_name'   => $student->last_name,
                    'gender'      => $student->gender,
                    'class_name'  => $student->classe ? $student->classe->name : 'N/A',
                ] : null,

                // Détails du forfait repas
                'meal_type' => $subscription->mealType ? [
                    'id'              => $subscription->mealType->id,
                    'name'            => $subscription->mealType->name,
                    'code'            => $subscription->mealType->code,
                    'price_per_month' => (int) $subscription->mealType->price_per_month,
                ] : null,

                // Tableau historique de tous les repas pris par cet élève
                'attendances' => $subscription->attendances->map(function ($att) {
                    return [
                        'id'              => $att->id,
                        'attendance_date' => $att->attendance_date ? $att->attendance_date->format('Y-m-d') : null,
                        'present'         => (bool) $att->present, // true = a mangé, false = absent
                        'notes'           => $att->notes,
                    ];
                }),

                // ─── AJOUT : historique des versements et renouvellements
                // — c'est le "cumul" affiché via le bouton dédié du tableau
                'payments' => $subscription->payments->map(function ($p) {
                    return [
                        'id'              => $p->id,
                        'receipt_number'  => $p->receipt_number,
                        'type'            => $p->type, // payment / renewal
                        'amount'          => (int) $p->amount,
                        'payment_date'    => $p->payment_date ? $p->payment_date->format('Y-m-d') : null,
                        'period_covered'  => $p->period_covered ? $p->period_covered->format('Y-m-d') : null,
                        'period_end'      => $p->period_end ? $p->period_end->format('Y-m-d') : null,
                    ];
                }),
            ];

            return response()->json([
                "status" => "success",
                "formatted" => $formatted,
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Fiche d’abonnement de cantine introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la récupération du dossier de cantine.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Modifie le statut ou les notes de l'abonnement de cantine d'un élève.
     * URL : PUT /api/canteen-subscriptions/{id}
     *
     * ─── RESTREINT VOLONTAIREMENT : cette route ne modifie plus que
     * status et notes. Le forfait, les dates et le montant total ne
     * passent plus que par renewSubscription() — permettre de les
     * modifier ici aussi créait une voie de contournement qui
     * court-circuitait toute la logique métier du renouvellement (pas de
     * reçu généré, pas de vérification que la période en cours est
     * soldée, pas de calcul automatique du nouveau total).
     */
    public function updateSubscription(Request $request, string $id)
    {
        assert_writable_year();
        try {
            $subscription = CanteenSubscription::findOrFail($id);

            // 1. Validation stricte — uniquement statut et notes
            $validated = $request->validate([
                'status' => ['required', 'in:active,inactive,suspended'], // active = Actif, suspended = Suspendu (ex: maladie)
                'notes'  => ['nullable', 'string'],
            ]);

            // 2. Mise à jour de l'enregistrement en base de données
            $subscription->update([
                'status' => $validated['status'],
                'notes'  => $validated['notes'] ?? null,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => "L'abonnement de cantine a été mis à jour avec succès."
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur de validation des champs fournis.',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Dossier d’abonnement de cantine introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur technique est survenue lors de la modification de l’abonnement.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }


    /**
 * Supprime la fiche d'abonnement de cantine d'un élève (Sécurisé).
    * URL : DELETE /api/canteen-subscriptions/{id}
    */
    public function destroySubscription(string $id)
    {
        assert_writable_year(); // ← AJOUT : empêche la suppression en consultation d'année archivée
        try {
            $subscription = CanteenSubscription::findOrFail($id);

            // 1. VERROU DE SÉCURITÉ COMPTABLE : Interdire la suppression s'il y a déjà eu encaissement
            if ((int) $subscription->amount_paid > 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Impossible de supprimer définitivement cet abonnement car un versement de " . number_format($subscription->amount_paid, 0, '', ' ') . " FCFA a déjà été encaissé en caisse. Veuillez plutôt modifier le statut de l'élève en 'Inactif'."
                ], 422);
            }

            // ─── AJOUT : VERROU DE TRAÇABILITÉ — un abonnement à 0 FCFA
            // payé peut quand même avoir été pointé à l'appel du
            // réfectoire, y compris sur des feuilles d'appel déjà
            // verrouillées. Le supprimer effacerait cet historique en
            // cascade (canteen_attendances.subscription_id est en
            // cascadeOnDelete), ce qui contredit le principe "aucun
            // delete définitif important" du projet.
            $attendanceCount = \App\Models\Canteen\CanteenAttendance::where('subscription_id', $subscription->id)->count();
            if ($attendanceCount > 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Impossible de supprimer cet abonnement car il possède un historique de présence à l'appel ({$attendanceCount} pointage(s) enregistré(s)), même sans versement associé. Veuillez plutôt modifier le statut de l'élève en 'Inactif' pour conserver la traçabilité."
                ], 422);
            }

            // 2. Suppression physique en base de données si aucun flux financier n'est enregistré
            $subscription->delete();

            return response()->json([
                'status'  => 'success',
                'message' => "Le dossier d'abonnement de cantine de l'élève a été retiré avec succès."
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Dossier d’abonnement de cantine introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors du retrait de l’abonnement de cantine.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Enregistre un versement de scolarité de cantine (Guichet Caisse Cantine).
     * URL : POST /api/canteen-subscriptions/{id}/pay
     */
    public function collectSubscriptionPayment(Request $request, string $id)
    {

        assert_writable_year();
        // 1. Validation du montant versé en Franc CFA (entier positif)
        $validated = $request->validate([
            'amount_to_pay' => ['required', 'integer', 'min:500'], // Minimum 500 FCFA par versement
        ]);

        try {
            $subscription = CanteenSubscription::findOrFail($id);

            // 2. Calcul des verrous financiers en base de données
            $totalDue = (int) $subscription->total_amount;
            $currentPaid = (int) $subscription->amount_paid;
            $remaining = max(0, $totalDue - $currentPaid);
            $newAmount = (int) $validated['amount_to_pay'];

            // SÉCURITÉ : Bloquer si le versement dépasse la dette de l'élève
            if ($newAmount > $remaining) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Erreur de caisse : Le montant saisi (" . number_format($newAmount, 0, '', ' ') . " FCFA) excède le reste à payer de l'élève (" . number_format($remaining, 0, '', ' ') . " FCFA)."
                ], 422);
            }

            // 3. Application comptable sécurisée dans une transaction
            return \Illuminate\Support\Facades\DB::transaction(function () use ($subscription, $currentPaid, $newAmount, $totalDue) {
                
                // Calcul du nouveau cumul payé
                $updatedPaid = $currentPaid + $newAmount;
                
                // Recalcul du statut de recouvrement
                if ($updatedPaid >= $totalDue) {
                    $paymentStatus = 'paid';
                } else {
                    $paymentStatus = 'partial';
                }

                // Mise à jour de la fiche d'abonnement
                $subscription->update([
                    'amount_paid'    => $updatedPaid,
                    'payment_status' => $paymentStatus
                ]);

                // ─── AJOUT : enregistrement du versement dans le vrai
                // registre CanteenPayment, avec reçu numéroté — jusqu'ici
                // seul amount_paid était incrémenté, aucune trace
                // individuelle du versement n'existait (pas d'historique,
                // pas de reçu imprimable, calcul des recettes mensuelles
                // impossible à faire correctement).
                $paymentDate = now()->format('Y-m-d');
                $receiptNumber = $this->generateCanteenReceiptNumber($paymentDate);

                $payment = CanteenPayment::create([
                    'canteen_subscription_id' => $subscription->id,
                    'amount'                  => $newAmount,
                    // ─── AJOUT : reste à payer figé juste après ce
                    // versement — 0 si l'abonnement devient soldé ici
                    'remaining_after'         => max(0, $totalDue - $updatedPaid),
                    'payment_date'            => $paymentDate,
                    // ─── CORRECTIF : utilise current_period_start (mis à
                    // jour à chaque renouvellement) au lieu de start_date
                    // (fixé une fois pour toutes à la création et jamais
                    // modifié) — sinon tout versement fait après un
                    // renouvellement retombait sur la toute première
                    // période de l'abonnement, jamais la période active.
                    'period_covered'          => $subscription->current_period_start ?? $subscription->start_date,
                    'period_end'              => $subscription->end_date,
                    'type'                    => 'payment',
                    'receipt_number'          => $receiptNumber,
                    'collected_by'            => Auth::id() ?? null,
                ]);

                return response()->json([
                    'status'  => 'success',
                    'message' => "Versement de " . number_format($newAmount, 0, '', ' ') . " FCFA enregistré avec succès. Le compte cantine de l'élève est actualisé.",
                    'receipt_id'     => $payment->id,
                    'receipt_number' => $receiptNumber,
                ], 200);
            });

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Abonnement de cantine introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la validation du versement en caisse.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * RENOUVELLE un abonnement de cantine pour une nouvelle période
     * (ex: mois suivant), SANS jamais demander à l'admin de recalculer un
     * total cumulé de tête. On lui demande uniquement le prix de LA
     * NOUVELLE période — le backend l'ajoute au total existant et
     * recalcule automatiquement le reste à payer.
     *
     * Différent de updateSubscription() : celui-ci reste réservé aux
     * corrections ponctuelles (changer le forfait, corriger une date),
     * pas aux renouvellements périodiques.
     *
     * URL : POST /api/canteen-subscriptions/{id}/renew
     */
    public function renewSubscription(Request $request, string $id)
    {
        assert_writable_year();

        // 1. Validation : le forfait est désormais choisi via une liste
        // (jamais un prix tapé librement — le tarif est TOUJOURS dérivé
        // du forfait sélectionné, calculé ici côté serveur, jamais fait
        // confiance à une valeur envoyée par le client).
        $validated = $request->validate([
            'meal_type_id'  => ['required', 'exists:meal_types,id'],   // Forfait de la nouvelle période
            'new_end_date'  => ['required', 'date'],                    // Nouvelle date de fin de couverture
            'payment_now'   => ['nullable', 'integer', 'min:0'],        // Versement immédiat optionnel pour cette période
            'notes'         => ['nullable', 'string'],
        ]);

        try {
            $subscription = CanteenSubscription::findOrFail($id);
            $mealType = \App\Models\Canteen\MealType::findOrFail($validated['meal_type_id']);

            // ─── VERROU : impossible de renouveler tant que la période en
            // cours n'est pas entièrement soldée — vérifié aussi côté
            // serveur, pas seulement via le bouton désactivé côté React.
            if ($subscription->payment_status !== 'paid') {
                $remaining = max(0, (int) $subscription->total_amount - (int) $subscription->amount_paid);
                return response()->json([
                    'status'  => 'error',
                    'message' => "Impossible de renouveler : il reste " . number_format($remaining, 0, '', ' ') . " FCFA à solder sur la période actuelle avant de pouvoir passer à la suivante."
                ], 422);
            }

            if ($validated['new_end_date'] <= $subscription->end_date->format('Y-m-d')) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "La nouvelle date de fin doit être postérieure à la période déjà couverte ({$subscription->end_date->format('d/m/Y')})."
                ], 422);
            }

            return DB::transaction(function () use ($subscription, $mealType, $validated) {
                // ─── AJOUT : capturé AVANT la mise à jour de l'abonnement
                // — c'est le point de départ de la nouvelle période
                // couverte par ce renouvellement (le lendemain de
                // l'ancienne date de fin), pas la date d'aujourd'hui.
                $newPeriodStart = $subscription->end_date->copy()->addDay()->format('Y-m-d');

                // ─── Le tarif de la nouvelle période vient TOUJOURS du
                // forfait choisi, jamais d'un champ libre — empêche toute
                // manipulation du prix, que ce soit par erreur de saisie
                // ou par une requête API construite à la main.
                $periodAmount = (int) $mealType->price_per_month;
                $paymentNow   = (int) ($validated['payment_now'] ?? 0);

                // ─── L'AJOUT, pas un total ressaisi : le total existant
                // (déjà payé + éventuel reliquat) reste intact, on ajoute
                // simplement le prix de la nouvelle période par-dessus.
                $newTotal = (int) $subscription->total_amount + $periodAmount;
                $newPaid  = (int) $subscription->amount_paid + $paymentNow;

                if ($newPaid > $newTotal) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => "Le versement saisi dépasse le montant dû pour cette période."
                    ], 422);
                }

                $paymentStatus = $newPaid >= $newTotal
                    ? 'paid'
                    : ($newPaid > 0 ? 'partial' : 'unpaid');

                $subscription->update([
                    'meal_type_id'          => $mealType->id, // ─── AJOUT : permet de changer de forfait au renouvellement
                    'end_date'              => $validated['new_end_date'],
                    // ─── AJOUT : fait avancer le marqueur de "période en
                    // cours" — c'est ce qui permet aux versements
                    // classiques suivants (guichet) de s'attribuer à la
                    // BONNE période, pas à la date de création originale.
                    'current_period_start'  => $newPeriodStart,
                    'total_amount'          => $newTotal,
                    'amount_paid'           => $newPaid,
                    'payment_status'        => $paymentStatus,
                    'status'                => 'active',
                    'notes'                 => $validated['notes'] ?? $subscription->notes,
                ]);

                // ─── AJOUT : si un versement immédiat accompagne le
                // renouvellement, on l'enregistre dans le registre
                // CanteenPayment (type 'renewal') avec son propre reçu
                // numéroté — même logique que pour un versement classique.
                $receiptNumber = null;
                if ($paymentNow > 0) {
                    $paymentDate = now()->format('Y-m-d');
                    $receiptNumber = $this->generateCanteenReceiptNumber($paymentDate);

                    CanteenPayment::create([
                        'canteen_subscription_id' => $subscription->id,
                        'amount'                  => $paymentNow,
                        // ─── AJOUT : reste à payer figé juste après ce
                        // versement de renouvellement
                        'remaining_after'         => max(0, $newTotal - $newPaid),
                        'payment_date'            => $paymentDate,
                        // ─── AJOUT : ce versement couvre la NOUVELLE
                        // période (dès le lendemain de l'ancienne fin
                        // jusqu'à la nouvelle date de fin CHOISIE ICI),
                        // pas le jour où le renouvellement est encaissé —
                        // et fige cette plage même si un futur
                        // renouvellement déplace encore la date de fin de
                        // l'abonnement plus tard.
                        'period_covered'          => $newPeriodStart,
                        'period_end'              => $validated['new_end_date'],
                        'type'                    => 'renewal',
                        'receipt_number'          => $receiptNumber,
                        'collected_by'            => Auth::id() ?? null,
                    ]);
                }

                return response()->json([
                    'status'  => 'success',
                    'message' => "Abonnement renouvelé jusqu'au " . \Illuminate\Support\Carbon::parse($validated['new_end_date'])->format('d/m/Y') . ". Période ajoutée : " . number_format($periodAmount, 0, '', ' ') . " FCFA.",
                    'subscription' => $subscription->fresh(),
                    'receipt_number' => $receiptNumber,
                ], 200);
            });

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Abonnement de cantine introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors du renouvellement.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }


  

    /**
     * Charge la liste des élèves rationnaires pour effectuer ou consulter l'appel d'un jour précis.
     * URL : GET /api/canteen-attendances?date=YYYY-MM-DD
     */
    public function indexAttendances(Request $request)
    {
        try {
            // 1. Déterminer la date cible de l'appel (prend la date du jour si non fournie)
            $date = $request->query('date') ?? Carbon::today()->format('Y-m-d');

            // ─── AJOUT : vérifie si l'appel de ce jour a déjà été
            // enregistré et verrouillé — le frontend doit alors passer en
            // lecture seule avec un message clair, plutôt que de laisser
            // croire qu'une nouvelle saisie est possible.
            $sheet = CanteenAttendanceSheet::with('submitter')
                ->where('attendance_date', $date)
                ->first();

            // 2. Récupérer tous les abonnements cantine actifs couvrant cette date
            $subscriptions = CanteenSubscription::with(['student.classe', 'mealType'])
                ->where('status', 'active')
                ->where('start_date', '<=', $date)
                ->where('end_date', '>=', $date)
                ->get();

            // 3. Récupérer les pointages déjà enregistrés pour ce jour précis
            $existingAttendances = CanteenAttendance::where('attendance_date', $date)
                ->pluck('present', 'subscription_id')
                ->toArray();

            // 4. Formatage structurel plat pour un mapping ultra-simple dans le switch React
            $formatted = $subscriptions->map(function ($sub) use ($existingAttendances) {
                $student = $sub->student;
                
                // Si l'appel a déjà été fait, on prend la valeur enregistrée. 
                // Sinon, l'élève est coché présent (true) par défaut.
                $isPresent = array_key_exists($sub->id, $existingAttendances) 
                    ? (bool) $existingAttendances[$sub->id] 
                    : true;

                return [
                    'id'              => $sub->id, // Identifiant pour la clé de boucle React
                    'subscription_id' => $sub->id,
                    'matricule'       => $student ? $student->matricule : 'N/A',
                    'first_name'      => $student ? $student->first_name : '',
                    'last_name'       => $student ? $student->last_name : '',
                    'class_name'      => ($student && $student->classe) ? $student->classe->name : 'N/A',
                    'meal_type_name'  => $sub->mealType ? $sub->mealType->name : 'Forfait',
                    'notes_allergie'  => $sub->notes, // Transmet les notes de régime médical au réfectoire
                    'present'         => $isPresent,  // État de la case à cocher à l'écran
                ];
            });

            // ─── AJOUT : réponse structurée avec l'état de verrouillage,
            // au lieu d'un simple tableau brut de présences.
            return response()->json([
                'locked'         => (bool) $sheet,
                'submitted_by'   => $sheet && $sheet->submitter ? $sheet->submitter->name : null,
                'submitted_at'   => $sheet ? $sheet->submitted_at->format('d/m/Y à H:i') : null,
                'records'        => $formatted,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur technique est survenue lors du chargement de la feuille d’appel.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Sauvegarde ou met à jour la feuille d'appel des repas de la cantine pour un jour précis.
     * URL : POST /api/canteen-attendances
     */
    public function storeAttendance(Request $request)
    {
        assert_writable_year();
        // 1. Validation de la structure globale de l'appel journalier
        $validated = $request->validate([
            'attendance_date' => ['required', 'date'],
            'records'         => ['required', 'array'],
            'records.*.subscription_id' => ['required', 'exists:canteen_subscriptions,id'],
            'records.*.present'         => ['required', 'in:0,1,true,false'],
            'records.*.notes'           => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $date = $validated['attendance_date'];

            // ─── AJOUT : verrou de traçabilité — refuse catégoriquement
            // tout nouvel enregistrement si l'appel de ce jour a déjà été
            // validé. Avant ce correctif, un second "Enregistrer" sur le
            // même jour écrasait silencieusement la première saisie, sans
            // aucune trace de qui avait pointé quoi ni quand.
            $existingSheet = CanteenAttendanceSheet::with('submitter')
                ->where('attendance_date', $date)
                ->first();

            if ($existingSheet) {
                $submitterName = $existingSheet->submitter->name ?? 'un agent';
                $submittedAt = $existingSheet->submitted_at->format('d/m/Y à H:i');
                return response()->json([
                    'status'  => 'error',
                    'message' => "Cet appel a déjà été enregistré et verrouillé par {$submitterName} le {$submittedAt}. Il ne peut plus être modifié."
                ], 422);
            }

            // 2. Traitement groupé et sécurisé dans une transaction de base de données
            DB::transaction(function () use ($validated, $date) {
                foreach ($validated['records'] as $record) {
                    // Conversion saine du booléen/entier pour MySQL
                    $isPresent = filter_var($record['present'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

                    CanteenAttendance::updateOrCreate(
                        [
                            'subscription_id' => $record['subscription_id'],
                            'attendance_date' => $date
                        ],
                        [
                            'present' => $isPresent,
                            'notes'   => $record['notes'] ?? null
                        ]
                    );
                }

                // ─── AJOUT : pose le verrou définitif pour cette date,
                // avec l'auteur et l'horodatage exact de la validation.
                CanteenAttendanceSheet::create([
                    'attendance_date' => $date,
                    'submitted_by'    => Auth::id() ?? null,
                    'submitted_at'    => now(),
                ]);
            });

            return response()->json([
                'status'  => 'success',
                'message' => "La feuille d'appel du réfectoire pour le " . \Illuminate\Support\Carbon::parse($date)->format('d/m/Y') . " a été enregistrée et verrouillée avec succès."
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => "Une erreur technique est survenue lors de l'enregistrement de l'appel.",
                'debug'   => $e->getMessage()
            ], 500);
        }
    }



    /**
     * Historique de tous les appels du réfectoire déjà enregistrés et
     * verrouillés — qui a validé, quand, et le décompte du jour.
     * URL : GET /api/canteen-attendances/history
     */
    public function canteenAttendanceHistory()
    {
        try {
            $sheets = CanteenAttendanceSheet::with('submitter')
                ->orderBy('attendance_date', 'desc')
                ->get();

            $formatted = $sheets->map(function ($sheet) {
                $presentCount = CanteenAttendance::where('attendance_date', $sheet->attendance_date->format('Y-m-d'))
                    ->where('present', 1)
                    ->count();
                $totalCount = CanteenAttendance::where('attendance_date', $sheet->attendance_date->format('Y-m-d'))
                    ->count();

                return [
                    'id'              => $sheet->id,
                    'attendance_date' => $sheet->attendance_date->format('Y-m-d'),
                    'submitted_by'    => $sheet->submitter->name ?? 'Système',
                    'submitted_at'    => $sheet->submitted_at->format('Y-m-d H:i'),
                    'present_count'   => $presentCount,
                    'total_count'     => $totalCount,
                ];
            });

            return response()->json($formatted, 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors du chargement de l’historique des appels.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Génère le PDF imprimable de la feuille d'appel d'un jour déjà
     * verrouillé — inclut qui a validé et à quelle heure.
     * URL : GET /api/canteen-attendances/{date}/pdf
     */
    public function canteenAttendanceSheetPdf(string $date)
    {
        try {
            $sheet = CanteenAttendanceSheet::with('submitter')
                ->where('attendance_date', $date)
                ->firstOrFail();

            $attendances = CanteenAttendance::with(['subscription.student.classe', 'subscription.mealType'])
                ->where('attendance_date', $date)
                ->get()
                ->sortBy(fn ($a) => $a->subscription->student->last_name ?? '')
                ->values();

            $establishment = \App\Models\Establishment::find(current_establishment_id());

            $data = [
                'sheet'         => $sheet,
                'attendances'   => $attendances,
                'establishment' => $establishment,
                'date'          => $date,
            ];

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.canteen-attendance-sheet-pdf', $data)
                ->setPaper('a4', 'portrait');

            return $pdf->stream("appel-cantine-{$date}.pdf");

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => "Aucun appel verrouillé n'existe pour cette date."
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la génération du PDF.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupère le grand livre de toutes les charges et dépenses de la cantine (Mois en cours).
     * URL : GET /api/canteen-expenses
     */
    public function indexExpenses()
    {
        try {
            // 1. Récupération des dates limites du mois en cours pour le filtrage comptable
            $startOfMonth = Carbon::now()->startOfMonth()->format('Y-m-d');
            $endOfMonth = Carbon::now()->endOfMonth()->format('Y-m-d');

            // 2. Extraction des dépenses du mois, triées de la plus récente à la plus ancienne
            $expenses = CanteenExpense::with(['creator'])
                ->whereBetween('expense_date', [$startOfMonth, $endOfMonth])
                ->orderBy('expense_date', 'desc')
                ->orderBy('id', 'desc')
                ->get();

            // 3. Reformatage adaptatif pour éliminer les boucles complexes côté React
            $formatted = $expenses->map(function ($exp) {
                return [
                    'id'           => $exp->id,
                    'title'        => $exp->title, // ex: Achat de 2 carcasses de mouton
                    'amount'       => (int) $exp->amount, // Forçage en entier pour le FCFA strict
                    'expense_date' => $exp->expense_date ? $exp->expense_date->format('Y-m-d') : null,
                    'category'     => $exp->category, // Riz, Viande, Poisson, Gaz...
                    'description'  => $exp->description,
                    'receipt'      => $exp->receipt, // Lien URL du justificatif numérisé
                    
                    // Traçabilité de l'agent de saisie comptable
                    'author_name'  => $exp->creator ? $exp->creator->name : 'Système',
                ];
            });

            return response()->json($formatted, 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors du chargement du livre des charges de la cantine.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Enregistre une nouvelle dépense d'achat (marché, gaz, entretien) pour la cuisine.
     * URL : POST /api/canteen-expenses
     */
    public function storeExpense(Request $request)
    {
        assert_writable_year(); // ← AJOUT : empêche la création en consultation d'année archivée
        // 1. Validation stricte du flux de sortie de caisse (FCFA obligatoirement entier positif)
        $validated = $request->validate([
            'title'        => ['required', 'string', 'max:150'],
            'amount'       => ['required', 'integer', 'min:100'], // Minimum 100 FCFA
            'expense_date' => ['required', 'date'],
            'category'     => ['required', 'in:Riz,Huile,Viande,Poisson,Légumes,Gaz,Charbon,Eau,Entretien,Autre'],
            'description'  => ['nullable', 'string'],
            'receipt'      => ['nullable', 'file', 'mimes:jpeg,png,jpg,pdf', 'max:2048'], // Justificatif Max 2 Mo
        ]);

        try {
            // 2. Traitement et stockage du reçu numérisé s'il est fourni
            $receiptPath = null;
            if ($request->hasFile('receipt')) {
                $file = $request->file('receipt');
                $receiptPath = $file->store('canteen/receipts', 'public');
            }

            // 3. Enregistrement de la sortie comptable en base de données
            $expense = CanteenExpense::create([
                'title'        => $validated['title'],
                'amount'       => (int) $validated['amount'],
                'expense_date' => $validated['expense_date'],
                'category'     => $validated['category'],
                'description'  => $validated['description'] ?? null,
                'receipt'      => $receiptPath ? '/storage/' . $receiptPath : null,
                'created_by'   => Auth::id() ?? null, // Traçabilité immédiate de l'auteur de la saisie
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => "La dépense de " . number_format($expense->amount, 0, '', ' ') . " FCFA pour '" . $expense->title . "' a été validée et enregistrée en caisse.",
                'expense' => $expense
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Données de facturation incorrectes ou incomplètes.',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur imprévue est survenue lors du décaissement.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Modifie une dépense de cantine existante.
     * URL : PUT /api/canteen-expenses/{id}
     */
   /**
 * Modifie une dépense de cantine existante (Prend en compte le changement de justificatif).
 * URL : PUT /api/canteen-expenses/{id}
 */
    public function updateExpense(Request $request, string $id)
    {
        assert_writable_year(); // ← AJOUT : empêche la modification en consultation d'année archivée
        try {
            $expense = CanteenExpense::findOrFail($id);

            // 1. Validation des modifications (Acceptation du reçu optionnel)
            $validated = $request->validate([
                'title'        => ['required', 'string', 'max:150'],
                'amount'       => ['required', 'integer', 'min:100'],
                'expense_date' => ['required', 'date'],
                'category'     => ['required', 'in:Riz,Huile,Viande,Poisson,Légumes,Gaz,Charbon,Eau,Entretien,Autre'],
                'description'  => ['nullable', 'string'],
                'receipt'      => ['nullable', 'file', 'mimes:jpeg,png,jpg,pdf', 'max:2048'], // Ajouté pour l'alignement
            ]);

            // Données de base à mettre à jour
            $updateData = [
                'title'        => $validated['title'],
                'amount'       => (int) $validated['amount'],
                'expense_date' => $validated['expense_date'],
                'category'     => $validated['category'],
                'description'  => $validated['description'] ?? null,
            ];

            // 2. Gestion du nouveau reçu si téléversé
            if ($request->hasFile('receipt')) {
                // Supprimer l'ancien fichier s'il existe pour éviter d'encombrer le serveur
                if ($expense->receipt) {
                    $oldPath = str_replace('/storage/', '', $expense->receipt);
                    if (Storage::disk('public')->exists($oldPath)) {
                        Storage::disk('public')->delete($oldPath);
                    }
                }

                // Stocker le nouveau fichier
                $file = $request->file('receipt');
                $receiptPath = $file->store('canteen/receipts', 'public');
                $updateData['receipt'] = '/storage/' . $receiptPath;
            }

            // 3. Mise à jour des informations en base de données
            $expense->update($updateData);

            return response()->json([
                'status'  => 'success',
                'message' => 'La dépense de cantine a été modifiée avec succès.'
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Données de modification incorrectes.',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Ligne de dépense introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la mise à jour de la dépense.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }



    /**
     * Supprime une ligne de dépense et nettoie son justificatif sur le serveur.
     * URL : DELETE /api/canteen-expenses/{id}
     */
    public function destroyExpense(string $id)
    {
        assert_writable_year(); // ← AJOUT : empêche la suppression en consultation d'année archivée
        try {
            // 1. Recherche de la ligne de dépense
            $expense = CanteenExpense::findOrFail($id);

            // 2. Nettoyage du fichier de justificatif s'il est présent sur le serveur
            if ($expense->receipt) {
                // Extraction du chemin relatif (retire le préfixe /storage/)
                $relativePath = str_replace('/storage/', '', $expense->receipt);
                if (Storage::disk('public')->exists($relativePath)) {
                    Storage::disk('public')->delete($relativePath);
                }
            }

            // 3. Suppression de la ligne en base de données
            $expense->delete();

            return response()->json([
                'status'  => 'success',
                'message' => 'Ligne de dépense et son justificatif supprimés avec succès.'
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Cette ligne de dépense n’existe pas ou a déjà été supprimée.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur technique est survenue lors de la suppression.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }



    /**
     * Récupère l'état des stocks et de l'épicerie de la cantine en magasin.
     * URL : GET /api/canteen/products
     */
    public function indexProducts()
    {
        try {
            // Extraction de toutes les denrées alimentaires répertoriées, triées par nom
            $products = CanteenProduct::orderBy('name', 'asc')->get();

            // Formatage plat et sécurisé pour faciliter le mapping et le switch d'alertes sur React
            $formatted = $products->map(function ($product) {
                $stockActuel = (int) $product->current_stock;
                $seuilAlerte = (int) $product->alert_threshold;

                return [
                    'id'              => $product->id,
                    'name'            => $product->name,            // ex: Sac de riz 50kg (Oignon)
                    'unit'            => $product->unit,            // ex: Sac, Litre, Carton, kg
                    'current_stock'   => $stockActuel,              // Quantité physique en magasin
                    'alert_threshold' => $seuilAlerte,              // Seuil de sécurité critique
                    'is_alert'        => $stockActuel <= $seuilAlerte, // Booléen d'alerte immédiat pour React
                ];
            });

            return response()->json($formatted, 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur technique est survenue lors du chargement de l’état des stocks.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Enregistre un mouvement de stock (Entrée d'achat ou Sortie cuisine) et met à jour l'économat.
     * URL : POST /api/canteen/products/movement
     */
    public function storeStockMovement(Request $request)
    {
        assert_writable_year(); // ─── AJOUT : bloque en consultation d'année archivée
        // 1. Validation stricte de la structure du mouvement
        $validated = $request->validate([
            'canteen_product_id' => ['required', 'exists:canteen_products,id'],
            'type'               => ['required', 'in:in,out'], // in = Entrée magasin, out = Sortie pour la cuisine
            'quantity'           => ['required', 'integer', 'min:1'], // Quantité entière positive
            'reason'             => ['required', 'string', 'max:150'], // Motif (ex: "Marché de gros", "Repas midi")
            'movement_date'      => ['required', 'date'],
        ]);

        try {
            // 2. Recherche de la denrée alimentaire ciblée
            $product = CanteenProduct::findOrFail($validated['canteen_product_id']);
            $qty = (int) $validated['quantity'];

            // 3. SÉCURITÉ COMPTABLE : Empêcher de sortir plus de nourriture qu'il n'y en a en stock
            if ($validated['type'] === 'out' && $qty > (int) $product->current_stock) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Opération refusée : Le stock de '" . $product->name . "' est insuffisant. Disponible : " . $product->current_stock . " " . $product->unit . "(s)."
                ], 422);
            }

            // 4. Exécution unifiée au sein d'une transaction SQL
            return DB::transaction(function () use ($validated, $product, $qty) {
                
                // Enregistrement de la ligne de mouvement (Traçabilité historique)
                $movement = CanteenStockMovement::create([
                    'canteen_product_id' => $validated['canteen_product_id'],
                    'type'               => $validated['type'],
                    'quantity'           => $qty,
                    'reason'             => $validated['reason'],
                    'movement_date'      => $validated['movement_date'],
                    'created_by'         => Auth::id() ?? null,
                ]);

                // Actualisation physique de la quantité globale en magasin
                if ($validated['type'] === 'in') {
                    $product->increment('current_stock', $qty); // + Ajout de stock
                } else {
                    $product->decrement('current_stock', $qty); // - Consommation cuisine
                }

                return response()->json([
                    'status'  => 'success',
                    'message' => "Mouvement comptabilisé. Le stock de '" . $product->name . "' a été mis à jour avec succès."
                ], 201);
            });

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la synchronisation de l’économat.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Ajoute une nouvelle denrée alimentaire au catalogue de référence.
     * URL : POST /api/canteen/products
     */
    public function storeProduct(Request $request)
    {
        assert_writable_year(); // ─── AJOUT : bloque en consultation d'année archivée
        $validated = $request->validate([
            'name'            => ['required', 'string', 'max:100', 'unique:canteen_products,name'],
            'unit'            => ['required', 'string', 'max:20'], // Sac, Litre, Carton, kg, etc.
            'alert_threshold' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $validated['current_stock'] = 0; // Initialisé à vide, les entrées se feront via les mouvements

            $product = CanteenProduct::create($validated);

            return response()->json([
                'status'  => 'success',
                'message' => "La denrée '{$product->name}' a été ajoutée au catalogue avec succès.",
                'product' => $product
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Impossible d’ajouter le produit au catalogue.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupère l'historique complet des mouvements pour la traçabilité.
     * URL : GET /api/canteen/products/movements
     */
    public function indexMovements()
    {
        try {
            // Récupération des mouvements avec les détails du produit lié, du plus récent au plus ancien
            $movements = CanteenStockMovement::with('product:id,name,unit')
                ->orderBy('movement_date', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();

            // ─── CORRECTIF : les modèles bruts étaient renvoyés tels
            // quels, donc movement_date sortait en ISO complet
            // ("2026-08-15T00:00:00.000000Z") au lieu d'une simple date —
            // le frontend l'affichait donc brut, avec un "00:00" trompeur
            // pour un champ qui n'a jamais eu d'heure. Reformaté comme le
            // reste des endpoints du contrôleur (Y-m-d), avec la relation
            // produit aplatie pour un mapping simple côté React.
            $formatted = $movements->map(function ($m) {
                return [
                    'id'             => $m->id,
                    'type'           => $m->type, // in / out
                    'quantity'       => (int) $m->quantity,
                    'reason'         => $m->reason,
                    'movement_date'  => $m->movement_date ? $m->movement_date->format('Y-m-d') : null,
                    'product_name'   => $m->product ? $m->product->name : 'Produit supprimé',
                    'product_unit'   => $m->product ? $m->product->unit : '',
                ];
            });

            return response()->json($formatted, 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors du chargement de l’historique des mouvements.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Récupère le carnet d'adresses de tous les fournisseurs de la cantine.
     * URL : GET /api/canteen/suppliers
     */
    public function indexSuppliers()
    {
        try {
            $suppliers = CanteenSupplier::orderBy('company_name', 'asc')->get();
            return response()->json($suppliers, 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Impossible de charger la liste des fournisseurs.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Enregistre un nouveau fournisseur de denrées alimentaires.
     * URL : POST /api/canteen/suppliers
     */
    public function storeSupplier(Request $request)
    {
        assert_writable_year(); // ─── AJOUT : bloque en consultation d'année archivée
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:150'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'phone'        => ['required', 'string', 'max:25'],
            'address'      => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $supplier = CanteenSupplier::create($validated);

            return response()->json([
                'status'   => 'success',
                'message'  => "Le fournisseur '{$supplier->company_name}' a été ajouté avec succès.",
                'supplier' => $supplier
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => "Erreur lors de l'enregistrement du fournisseur.",
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Historique global de tous les versements cantine (paiements et
     * renouvellements confondus), toutes fiches confondues.
     * URL : GET /api/canteen-payments/receipts
     */
    public function canteenReceiptsHistory()
    {
        try {
            $payments = CanteenPayment::with(['subscription.student.classe', 'collector'])
                ->orderBy('payment_date', 'desc')
                ->orderBy('id', 'desc')
                ->get();

            $formatted = $payments->map(function ($p) {
                $sub = $p->subscription;
                $student = $sub ? $sub->student : null;

                return [
                    'id'             => $p->id,
                    'receipt_number' => $p->receipt_number,
                    'type'           => $p->type, // payment / renewal
                    'amount'         => (int) $p->amount,
                    'payment_date'   => $p->payment_date ? $p->payment_date->format('Y-m-d') : null,

                    'student_name'   => $student ? ($student->last_name . ' ' . $student->first_name) : 'Élève inconnu',
                    'matricule'      => $student ? $student->matricule : 'N/A',
                    'class_name'     => ($student && $student->classe) ? $student->classe->name : 'N/A',

                    'collector_name' => $p->collector ? $p->collector->name : 'Système',
                ];
            });

            return response()->json($formatted, 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors du chargement de l’historique des versements.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Génère le PDF imprimable d'un reçu individuel de versement cantine.
     * URL : GET /api/canteen-payments/{id}/receipt/pdf
     */
    public function canteenPaymentReceiptPdf(string $id)
    {
        try {
            $payment = CanteenPayment::with(['subscription.student.classe', 'subscription.mealType', 'collector'])
                ->findOrFail($id);

            $establishment = \App\Models\Establishment::find(current_establishment_id());

            $data = [
                'payment'       => $payment,
                'subscription'  => $payment->subscription,
                'student'       => $payment->subscription ? $payment->subscription->student : null,
                'establishment' => $establishment,
                'generated_at'  => now(),
            ];

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.canteen-payment-receipt-pdf', $data)
                ->setPaper('a5', 'portrait');

            return $pdf->stream("recu-cantine-{$payment->receipt_number}.pdf");

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Reçu de versement introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la génération du reçu PDF.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // RAPPORT FINANCIER CANTINE — résumé, dépenses par catégorie,
    // recouvrement, évolution mensuelle, impayés. Écran + PDF + Excel.
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Calcule toutes les données du rapport financier cantine pour une
     * période donnée — réutilisée par les 3 formats (JSON, PDF, Excel).
     */
    private function computeCanteenReportData(string $from, string $to): array
    {
        // A. Résumé — recettes réelles (registre CanteenPayment) vs dépenses
        $totalReceipts = (int) CanteenPayment::whereBetween('payment_date', [$from, $to])->sum('amount');
        $totalExpenses = (int) CanteenExpense::whereBetween('expense_date', [$from, $to])->sum('amount');
        $netBalance = $totalReceipts - $totalExpenses;
        $isProfit = $netBalance >= 0;
        $coverageRate = $totalExpenses > 0 ? round(($totalReceipts / $totalExpenses) * 100) : 100;

        // B. Répartition des dépenses par catégorie sur la période
        $expensesByCategory = CanteenExpense::whereBetween('expense_date', [$from, $to])
            ->select('category', DB::raw('SUM(amount) as total'))
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['category' => $row->category, 'total' => (int) $row->total])
            ->values();

        // C. Snapshot recouvrement — sur les abonnements actifs à ce jour
        // (indépendant de la période choisie, c'est un état présent)
        $subscriptions = CanteenSubscription::all();
        $paidCount = $subscriptions->where('payment_status', 'paid')->count();
        $partialCount = $subscriptions->where('payment_status', 'partial')->count();
        $unpaidCount = $subscriptions->where('payment_status', 'unpaid')->count();
        $paidTotal = (int) $subscriptions->where('payment_status', 'paid')->sum('amount_paid');
        $partialTotal = (int) $subscriptions->where('payment_status', 'partial')->sum('amount_paid');
        $unpaidTotal = (int) $subscriptions->where('payment_status', 'unpaid')->sum('amount_paid');

        // D. Évolution mensuelle — 6 derniers mois glissants
        $monthlyTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthStart = Carbon::now()->subMonths($i)->startOfMonth();
            $monthEnd = Carbon::now()->subMonths($i)->endOfMonth();

            $monthlyTrend[] = [
                'label'    => $monthStart->translatedFormat('M Y'),
                'receipts' => (int) CanteenPayment::whereBetween('payment_date', [$monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')])->sum('amount'),
                'expenses' => (int) CanteenExpense::whereBetween('expense_date', [$monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')])->sum('amount'),
            ];
        }

        // E. Liste des impayés/avances, triée par reste dû décroissant
        $unpaidList = CanteenSubscription::with(['student.classe', 'mealType'])
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->get()
            ->map(function ($sub) {
                $student = $sub->student;
                return [
                    'student_name' => $student ? ($student->last_name . ' ' . $student->first_name) : 'Élève inconnu',
                    'matricule'    => $student ? $student->matricule : 'N/A',
                    'class_name'   => ($student && $student->classe) ? $student->classe->name : 'N/A',
                    'meal_type'    => $sub->mealType ? $sub->mealType->name : 'N/A',
                    'total_amount' => (int) $sub->total_amount,
                    'amount_paid'  => (int) $sub->amount_paid,
                    'remaining'    => (int) max(0, $sub->total_amount - $sub->amount_paid),
                    'payment_status' => $sub->payment_status,
                ];
            })
            ->sortByDesc('remaining')
            ->values();

        return [
            'summary' => [
                'total_receipts' => $totalReceipts,
                'total_expenses' => $totalExpenses,
                'net_balance'    => $netBalance,
                'is_profit'      => $isProfit,
                'coverage_rate'  => $coverageRate,
            ],
            'expenses_by_category' => $expensesByCategory,
            'recovery' => [
                'paid'    => ['count' => $paidCount, 'total' => $paidTotal],
                'partial' => ['count' => $partialCount, 'total' => $partialTotal],
                'unpaid'  => ['count' => $unpaidCount, 'total' => $unpaidTotal],
            ],
            'monthly_trend' => $monthlyTrend,
            'unpaid_list'   => $unpaidList,
        ];
    }

    /**
     * URL : GET /api/canteen/reports?from=...&to=...
     */
    public function financialReport(Request $request)
    {
        try {
            $from = $request->query('from', Carbon::now()->startOfMonth()->format('Y-m-d'));
            $to   = $request->query('to', Carbon::now()->endOfMonth()->format('Y-m-d'));

            $data = $this->computeCanteenReportData($from, $to);

            return response()->json($data, 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors du calcul du rapport financier.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * URL : GET /api/canteen/reports/pdf
     */
    public function financialReportPdf(Request $request)
    {
        try {
            $from = $request->query('from', Carbon::now()->startOfMonth()->format('Y-m-d'));
            $to   = $request->query('to', Carbon::now()->endOfMonth()->format('Y-m-d'));

            $data = $this->computeCanteenReportData($from, $to);
            $data['from'] = $from;
            $data['to'] = $to;
            $data['establishment'] = \App\Models\Establishment::find(current_establishment_id());
            $data['generated_at'] = now();

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.canteen-report-pdf', $data)
                ->setPaper('a4', 'portrait');

            return $pdf->stream("rapport-financier-cantine-{$from}-au-{$to}.pdf");

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la génération du rapport PDF.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * URL : GET /api/canteen/reports/excel
     */
    public function financialReportExcel(Request $request)
    {
        try {
            $from = $request->query('from', Carbon::now()->startOfMonth()->format('Y-m-d'));
            $to   = $request->query('to', Carbon::now()->endOfMonth()->format('Y-m-d'));

            $data = $this->computeCanteenReportData($from, $to);

            return Excel::download(new CanteenReportExport($data, $from, $to), "rapport-financier-cantine-{$from}-au-{$to}.xlsx");

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la génération du fichier Excel.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // CARTE CANTINE + QR CODE — routes PUBLIQUES (hors auth:sanctum),
    // consultées en scannant le QR imprimé sur la carte physique de
    // l'élève. Aucune donnée sensible (pas de compte, pas de mot de
    // passe) — uniquement l'état de l'abonnement cantine.
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Génère la carte cantine imprimable au format PDF.
     * ─── SIMPLIFIÉ : le QR code n'est plus généré ici — il est produit
     * côté React (comme pour Transport), via un service externe léger,
     * et pointe simplement vers CETTE route. Aucune dépendance PHP au QR,
     * aucune page de vérification séparée à maintenir : scanner le QR
     * régénère cette même carte à jour.
     * URL PUBLIQUE : GET /api/public/canteen-card/{id}/pdf
     */
    public function canteenCardPdf(string $id)
    {
        try {
            $subscription = CanteenSubscription::with(['student.classe', 'mealType'])->findOrFail($id);

            // ─── Établissement dérivé directement de la colonne de
            // l'abonnement, PAS des helpers current_establishment_*()
            // (qui dépendent d'une session authentifiée — inexistante
            // ici puisque cette route est publique, sans connexion).
            $establishment = \App\Models\Establishment::find($subscription->establishment_id);

            $remaining = max(0, (int) $subscription->total_amount - (int) $subscription->amount_paid);

            $data = [
                'subscription'      => $subscription,
                'student'           => $subscription->student,
                'establishmentName' => $establishment->name ?? 'Établissement scolaire',
                'remaining'         => $remaining,
            ];

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.canteen-card-pdf', $data);

            $matricule = $subscription->student->matricule ?? $subscription->id;
            return $pdf->stream("carte-cantine-{$matricule}.pdf");

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            abort(404, 'Carte de cantine introuvable.');
        } catch (\Exception $e) {
            abort(500, 'Erreur lors de la génération du document.');
        }
    }











}