<?php

namespace App\Http\Controllers;

use App\Models\Academic\AcademicYears;
use App\Models\Academic\Classe;
use App\Models\Academic\Student;
use App\Models\Canteen\CanteenSubscription;
use App\Models\EstablishmentActivityLog;
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
    public function store(Request $request)
    {
        assert_writable_year();
        $validated = $request->validate([
            'enrollment_id'         => ['required', 'exists:enrollments,id'],
            'amount_paid'           => ['required', 'integer', 'min:500'],
            'payment_date'          => ['required', 'date'],
            'payment_method'        => ['required', 'in:cash,wave,orange_money,mtn_money,moov_money,virement'],
            'transaction_reference' => ['nullable', 'string', 'max:100'],
            'notes'                 => ['nullable', 'string'],
        ]);

        $enrollment = Enrollment::with('financial')->findOrFail($request->enrollment_id);
        $financial = $enrollment->financial;

        $totalDue = (int) $financial->total_due;
        $currentPaid = (int) $financial->initial_payment;
        $resteA_Payer = $totalDue - $currentPaid;

        if ($validated['amount_paid'] > $resteA_Payer) {
            return response()->json([
                'status'  => 'error',
                'message' => "Erreur : Le montant saisi (" . number_format($validated['amount_paid'], 0, '', ' ') . " FCFA) excède le reste à payer de l'élève (" . number_format($resteA_Payer, 0, '', ' ') . " FCFA)."
            ], 422);
        }

        return DB::transaction(function () use ($validated, $enrollment, $financial) {
            // ─── CORRECTIF : numéro de reçu préfixé par ÉTABLISSEMENT ───
            // Sans ce préfixe, deux établissements distincts (typiquement le
            // parent et un enfant du même groupe scolaire) calculent chacun
            // "leur premier reçu de l'année" comme REC-{ANNEE}-00001, et
            // entrent en collision sur la contrainte d'unicité globale de la
            // table payments dès que le 2e essaie d'insérer le sien.
            $estabPrefix = current_establishment_prefix();
            $year = date('Y');
            $receiptPrefix = "REC-{$estabPrefix}-{$year}-";

            $lastPayment = Payment::whereRaw("receipt_number LIKE '{$receiptPrefix}%'")->latest('id')->first();
            $next = $lastPayment ? ((int) substr($lastPayment->receipt_number, -5)) + 1 : 1;
            $receiptNumber = $receiptPrefix . str_pad($next, 5, '0', STR_PAD_LEFT);

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

            $financial->increment('initial_payment', $validated['amount_paid']);

            // ─── Journal d'activité établissement ───
            $studentName = optional($enrollment->student)->first_name . ' ' . optional($enrollment->student)->last_name;
            EstablishmentActivityLog::record(
                Auth::user(),
                'payment.collected',
                "A encaissé " . number_format($validated['amount_paid'], 0, '', ' ') . " FCFA pour \"{$studentName}\" (reçu {$receiptNumber}).",
                'Payment',
                $payment->id
            );

            return response()->json([
                'status'         => 'success',
                'message'        => "Encaissement enregistré avec succès sous le reçu {$receiptNumber}.",
                'receipt_number' => $receiptNumber
            ], 201);
        });
    }

    public function receiptsHistory(Request $request)
    {
        $query = Payment::with(['enrollment.student', 'enrollment.classe', 'enrollment.financial']);

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

        if ($request->filled('class_id')) {
            $query->whereHas('enrollment', function ($q) use ($request) {
                $q->where('class_id', $request->query('class_id'));
            });
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->query('payment_method'));
        }

        if ($request->filled('start_date')) {
            $query->whereDate('payment_date', '>=', $request->query('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('payment_date', '<=', $request->query('end_date'));
        }

        $receipts = $query->latest('payment_date')->get();

        $formatted = $receipts->map(function ($p) {
            $student = $p->enrollment->student;

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
                'photo'             => $student->photo,
                'class_name'        => $student->classe ? $student->classe->name : 'N/A',
                'cycle'             => ($student->classe && $student->classe->level) ? $student->classe->level->name : 'N/A',
                'enrollment_number' => $enrollment->enrollment_number,
                'financial' => [
                    'total_due'    => $totalDue,
                    'total_paid'   => $totalPaid,
                    'remaining'    => $remaining,
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
    public function getScolariteMetrics()
    {
        try {
            $today = Carbon::today()->format('Y-m-d');
            $establishmentId = current_establishment_id();

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

            $totalAttendu = EnrollmentFinancial::whereHas('enrollment', function ($q) use ($activeYearId, $establishmentId) {
                $q->where('academic_year_id', $activeYearId)
                  ->whereHas('student', fn($s) => $s->where('establishment_id', $establishmentId));
            })->sum('total_due');

            $totalEncaisse = EnrollmentFinancial::whereHas('enrollment', function ($q) use ($activeYearId, $establishmentId) {
                $q->where('academic_year_id', $activeYearId)
                  ->whereHas('student', fn($s) => $s->where('establishment_id', $establishmentId));
            })->sum('initial_payment');

            $resteA_Recouvrer = max(0, $totalAttendu - $totalEncaisse);
            $tauxRecouvrement = $totalAttendu > 0 ? round(($totalEncaisse / $totalAttendu) * 100, 1) : 0;

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

    public function getStudentsSolded()
    {
        $soldes = Enrollment::with(['student.classe', 'financial'])
            ->whereHas('financial', function($q) {
                $q->whereRaw('initial_payment >= total_due');
            })->get();

        return response()->json($this->formatStudentFinanceList($soldes), 200);
    }

    public function getStudentsWithDebt()
    {
        $debiteurs = Enrollment::with(['student.classe', 'financial'])
            ->whereHas('financial', function($q) {
                $q->whereRaw('initial_payment < total_due');
            })->get();

        return response()->json($this->formatStudentFinanceList($debiteurs), 200);
    }

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