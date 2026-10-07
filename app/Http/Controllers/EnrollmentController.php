<?php

namespace App\Http\Controllers;

use App\Models\Academic\Classe;
use App\Models\Academic\Student;
use App\Models\Establishment;
use App\Models\officeAdministration\Enrollment;
use App\Models\officeAdministration\EnrollmentFinancial;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EnrollmentController extends Controller
{
    public function store(Request $request)
    {
        assert_writable_year();

        $validated = $request->validate([
            'student_id'       => ['required', 'exists:students,id'],
            'class_id'         => ['required', 'exists:classes,id'],
            'academic_year_id' => ['required', 'exists:academic_years,id'],
            'type'             => ['required', 'in:new,re_enrollment'],
            'enrollment_date'  => ['required', 'date'],
            'notes'            => ['nullable', 'string'],

            'registration_fee' => ['required', 'integer', 'min:0'],
            'tuition_fee'      => ['required', 'integer', 'min:0'],
            'annex_fee'        => ['required', 'integer', 'min:0'],
            'discount_amount'  => ['nullable', 'integer', 'min:0'],
            'initial_payment'  => ['required', 'integer', 'min:0'],
            'payment_method'   => ['required', 'in:cash,online,none'],
        ]);

        $yearOwned = \App\Models\Academic\AcademicYears::where('id', $validated['academic_year_id'])
            ->where('establishment_id', current_establishment_id())
            ->exists();

        if (!$yearOwned) {
            return response()->json([
                'status'  => 'error',
                'message' => "Année scolaire invalide pour votre établissement."
            ], 422);
        }

        $classe = Classe::findOrFail($validated['class_id']);
        if ($classe->academic_year_id != $validated['academic_year_id']) {
            return response()->json([
                'status'  => 'error',
                'message' => "Cette classe n'appartient pas à l'année scolaire sélectionnée."
            ], 422);
        }

        $currentStudent = Student::with('classe')->findOrFail($validated['student_id']);

        $lastDecision = \App\Models\Academic\StudentAcademicRecord::withoutGlobalScope('viewingYear')
            ->where('student_id', $currentStudent->id)
            ->orderByDesc('academic_year_id')
            ->value('year_end_decision');

        if ($lastDecision === 'E') {
            return response()->json([
                'status'  => 'error',
                'message' => "Cet élève a été exclu et ne peut plus être inscrit ni réinscrit."
            ], 422);
        }

        $previousBalance = 0;

        if ($validated['type'] === 're_enrollment') {
            $lastEnrollment = Enrollment::withoutGlobalScope('viewingYear')
                ->where('student_id', $currentStudent->id)
                ->where('status', '!=', 'cancelled')
                ->orderByDesc('academic_year_id')
                ->orderByDesc('id')
                ->first();

            if ($lastEnrollment) {
                $financial = \App\Models\officeAdministration\EnrollmentFinancial::withoutGlobalScopes()
                    ->where('enrollment_id', $lastEnrollment->id)
                    ->first();

                if ($financial) {
                    $previousBalance = max(0, (int) $financial->total_due - (int) $financial->initial_payment);
                }
            }

            if ($lastDecision === 'R') {
                $currentLevelId = $currentStudent->classe?->level_id;
                $targetLevelId  = $classe->level_id ?? null;

                if (!$currentLevelId || $targetLevelId != $currentLevelId) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => "Cet élève redouble : il ne peut être réinscrit que dans une classe du même niveau que sa classe actuelle."
                    ], 422);
                }
            }
        }

        // ─── CORRECTIF : une inscription ANNULÉE ne doit plus jamais
        // bloquer une nouvelle tentative pour ce même élève/année — sinon
        // une erreur de saisie annulée condamnerait définitivement l'élève
        // à ne plus jamais pouvoir être inscrit cette année-là. On garde
        // volontairement la ligne annulée en base (traçabilité/historique,
        // conforme au principe "aucun delete définitif" de ce projet) —
        // elle est juste exclue de cette vérification précise. ───
        $exists = Enrollment::where('student_id', $validated['student_id'])
            ->where('academic_year_id', $validated['academic_year_id'])
            ->where('status', '!=', 'cancelled')
            ->exists();

        if ($exists) {
            return response()->json([
                'status'  => 'error',
                'message' => "Cet élève dispose déjà d'un dossier d'inscription actif pour cette année académique."
            ], 422);
        }

        $maxCapacity = (int) $classe->capacity;

        $currentCount = Student::where('class_id', $validated['class_id'])
            ->where('academic_year_id', $validated['academic_year_id'])
            ->where('id', '!=', $validated['student_id'])
            ->count();

        if ($currentCount >= $maxCapacity) {
            return response()->json([
                'status'  => 'error',
                'message' => "Surcharge refusée : La classe '{$classe->name}' a atteint sa capacité limite autorisée de {$maxCapacity} places pour cette année académique."
            ], 422);
        }

        $userId = Auth::id();

        return DB::transaction(function () use ($validated, $userId, $previousBalance) {

            $estabPrefix = current_establishment_prefix();
            $yearShort   = current_school_year_short();
            $insPrefix   = "INS-{$estabPrefix}-{$yearShort}-";

            $lastEnrollment = Enrollment::where('enrollment_number', 'LIKE', $insPrefix . '%')
                ->latest('id')
                ->first();

            if ($lastEnrollment) {
                $lastNumber = (int) substr($lastEnrollment->enrollment_number, -5);
                $nextNumber = $lastNumber + 1;
            } else {
                $nextNumber = 1;
            }

            $enrollmentNumber = $insPrefix . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);

            $enrollment = Enrollment::create([
                'student_id'        => $validated['student_id'],
                'class_id'          => $validated['class_id'],
                'academic_year_id'  => $validated['academic_year_id'],
                'enrollment_number' => $enrollmentNumber,
                'type'              => $validated['type'],
                'enrollment_date'   => $validated['enrollment_date'],
                'notes'             => $validated['notes'] ?? null,
                'created_by'        => $userId,
            ]);

            $discountAmount = $validated['discount_amount'] ?? 0;
            $subtotal = $validated['registration_fee'] + $validated['tuition_fee'] + $validated['annex_fee'];
            $totalDue = $subtotal + $previousBalance - $discountAmount;
            if ($totalDue < 0) $totalDue = 0;

            EnrollmentFinancial::create([
                'enrollment_id'    => $enrollment->id,
                'registration_fee' => $validated['registration_fee'],
                'tuition_fee'      => $validated['tuition_fee'],
                'annex_fee'        => $validated['annex_fee'],
                'discount_amount'  => $discountAmount,
                'previous_balance' => $previousBalance,
                'total_due'        => $totalDue,
                'initial_payment'  => $validated['initial_payment'],
                'payment_method'   => $validated['payment_method'],
            ]);

            $student = Student::find($validated['student_id']);
            $student->update([
                'class_id'         => $validated['class_id'],
                'academic_year_id' => $validated['academic_year_id'],
            ]);

            return response()->json([
                'status'            => 'success',
                'message'           => "Inscription validée avec succès sous le numéro {$enrollmentNumber}.",
                'enrollment_number' => $enrollmentNumber,
                'id'                => $enrollment->id,
            ], 201);
        });
    }

    public function index()
    {
        $enrollments = Enrollment::with([
            'student',
            'classe.level',
            'academicYear'
        ])->latest()->get();

        $formatted = $enrollments->map(function ($enr) {
            return [
                'id'                => $enr->id,
                'enrollment_number' => $enr->enrollment_number,
                'type'              => $enr->type,
                'status'            => $enr->status,
                'enrollment_date'   => $enr->enrollment_date ? $enr->enrollment_date->format('Y-m-d') : null,
                'notes'             => $enr->notes,
                'class_id'          => $enr->class_id,
                'class'             => $enr->classe ? $enr->classe->name : 'N/A',
                'cycle'             => ($enr->classe && $enr->classe->level) ? $enr->classe->level->name : 'N/A',
                'academic_year'     => $enr->academicYear ? $enr->academicYear->name : 'N/A',
                'student'           => [
                    'id'         => $enr->student ? $enr->student->id : null,
                    'first_name' => $enr->student ? $enr->student->first_name : '',
                    'last_name'  => $enr->student ? $enr->student->last_name : '',
                    'gender'     => $enr->student ? $enr->student->gender : 'M',
                    'matricule'  => $enr->student ? $enr->student->matricule : '',
                ]
            ];
        });

        return response()->json($formatted, 200);
    }

    public function validateEnrollment(string $id)
    {
        assert_writable_year();
        try {
            $enrollment = Enrollment::findOrFail($id);

            $enrollment->update([
                'status'       => 'validated',
                'validated_by' => Auth::id(),
                'validated_at' => now(),
            ]);

            $student = Student::findOrFail($enrollment->student_id);
            $student->update([
                'is_enrolled' => 1,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Inscription validée.'
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Inscription introuvable.'], 404);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function cancelEnrollment(string $id)
    {
        assert_writable_year();
        $enrollment = Enrollment::findOrFail($id);

        return DB::transaction(function () use ($enrollment) {
            $enrollment->update(['status' => 'cancelled']);

            // ─── CORRECTIF CENTRAL : le compteur de places d'une classe se
            // base sur students.class_id (pas sur le statut de l'inscription).
            // Annuler l'inscription SANS libérer ce champ laissait l'élève
            // "compté" comme occupant toujours une place, même annulé —
            // faussant la capacité affichée au moment de choisir la classe
            // de destination pour une nouvelle inscription. ───
            $student = Student::find($enrollment->student_id);

            if ($student
                && (int) $student->class_id === (int) $enrollment->class_id
                && (int) $student->academic_year_id === (int) $enrollment->academic_year_id
            ) {
                // Cherche s'il existe une AUTRE inscription active (non
                // annulée) pour ce même élève sur cette même année — cas
                // normalement impossible vu la contrainte anti-doublon,
                // mais on vérifie par sécurité avant de vider le champ.
                $stillActiveElsewhere = Enrollment::withoutGlobalScope('viewingYear')
                    ->where('student_id', $student->id)
                    ->where('academic_year_id', $enrollment->academic_year_id)
                    ->where('status', '!=', 'cancelled')
                    ->exists();

                if (!$stillActiveElsewhere) {
                    $student->update([
                        'class_id'         => null,
                        'academic_year_id' => null,
                        'is_enrolled'      => false,
                    ]);
                }
            }

            return response()->json(['status' => 'success', 'message' => 'Inscription annulée. La place a été libérée dans la classe.']);
        });
    }

    /**
     * GUICHET DE TRANSFERT — muter / réorienter un élève inscrit vers une
     * autre classe de la MÊME année. Transfert purement administratif : les
     * montants (total_due, acompte, paiements) ne sont jamais modifiés.
     *
     * Répercute le changement sur les TROIS endroits qui portent la classe :
     *   1. enrollments.class_id
     *   2. students.class_id (+ academic_year_id)
     *   3. la fiche StudentAcademicRecord de l'année de l'inscription
     * et écrit une ligne d'historique (enrollment_transfers).
     *
     * POST /enrollments/{id}/transfer   { to_class_id, reason }
     */
    public function transfer(Request $request, string $id)
    {
        assert_writable_year();

        $validated = $request->validate([
            'to_class_id' => ['required', 'exists:classes,id'],
            'reason'      => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'to_class_id.required' => 'Veuillez choisir la classe de destination.',
            'reason.required'      => 'Le motif du transfert est obligatoire.',
        ]);

        $enrollment = Enrollment::findOrFail($id);

        if ($enrollment->status === 'cancelled') {
            return response()->json([
                'status'  => 'error',
                'message' => "Une inscription annulée ne peut pas être transférée.",
            ], 422);
        }

        $target = Classe::findOrFail($validated['to_class_id']);

        if ((int) $target->academic_year_id !== (int) $enrollment->academic_year_id) {
            return response()->json([
                'status'  => 'error',
                'message' => "La classe de destination n'appartient pas à l'année scolaire de cette inscription.",
            ], 422);
        }

        if ((int) $target->id === (int) $enrollment->class_id) {
            return response()->json([
                'status'  => 'error',
                'message' => "L'élève est déjà dans cette classe.",
            ], 422);
        }

        $maxCapacity = (int) $target->capacity;
        $currentCount = Student::where('class_id', $target->id)
            ->where('academic_year_id', $enrollment->academic_year_id)
            ->where('id', '!=', $enrollment->student_id)
            ->count();

        if ($maxCapacity > 0 && $currentCount >= $maxCapacity) {
            return response()->json([
                'status'  => 'error',
                'message' => "Transfert refusé : la classe '{$target->name}' a atteint sa capacité limite de {$maxCapacity} places.",
            ], 422);
        }

        return DB::transaction(function () use ($enrollment, $target, $validated) {
            $fromClassId = $enrollment->class_id;

            // 1. Inscription
            $enrollment->update(['class_id' => $target->id]);

            // 2. Dossier élève
            $student = Student::findOrFail($enrollment->student_id);
            $student->update([
                'class_id'         => $target->id,
                'academic_year_id' => $enrollment->academic_year_id,
            ]);

            // 3. Fiche académique de l'année de l'inscription
            $record = \App\Models\Academic\StudentAcademicRecord::withoutGlobalScope('viewingYear')
                ->where('student_id', $student->id)
                ->where('academic_year_id', $enrollment->academic_year_id)
                ->first();

            if ($record) {
                $record->update(['class_id' => $target->id]);
            } else {
                \App\Models\Academic\StudentAcademicRecord::create([
                    'student_id'       => $student->id,
                    'academic_year_id' => $enrollment->academic_year_id,
                    'class_id'         => $target->id,
                ]);
            }

            // Historique
            \App\Models\officeAdministration\EnrollmentTransfer::create([
                'enrollment_id'    => $enrollment->id,
                'student_id'       => $student->id,
                'academic_year_id' => $enrollment->academic_year_id,
                'from_class_id'    => $fromClassId,
                'to_class_id'      => $target->id,
                'reason'           => $validated['reason'],
                'transferred_by'   => Auth::id(),
                'transferred_at'   => now(),
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => "Élève transféré vers la classe {$target->name}.",
            ]);
        });
    }

    /**
     * Historique des transferts. ?enrollment_id=X pour une inscription précise,
     * sinon tous les transferts de l'année consultée.
     * GET /enrollments-transfers
     */
    public function transfersHistory(Request $request)
    {
        $query = \App\Models\officeAdministration\EnrollmentTransfer::with([
                'student', 'fromClass', 'toClass', 'author', 'enrollment',
            ])
            ->orderByDesc('transferred_at')
            ->orderByDesc('id');

        if ($request->filled('enrollment_id')) {
            $query->where('enrollment_id', $request->enrollment_id);
        } else {
            $query->where('academic_year_id', current_viewing_year_id());
        }

        $rows = $query->limit(500)->get()->map(function ($t) {
            return [
                'id'                => $t->id,
                'transferred_at'    => $t->transferred_at ? $t->transferred_at->toISOString() : null,
                'reason'            => $t->reason,
                'from_class'        => $t->fromClass?->name ?? '—',
                'to_class'          => $t->toClass?->name ?? '—',
                'author'            => $t->author?->name ?? '—',
                'enrollment_number' => $t->enrollment?->enrollment_number,
                'student'           => [
                    'id'         => $t->student?->id,
                    'first_name' => $t->student?->first_name ?? '',
                    'last_name'  => $t->student?->last_name ?? '',
                    'matricule'  => $t->student?->matricule ?? '',
                ],
            ];
        });

        return response()->json($rows, 200);
    }

    public function show(string $id)
    {
        try {
            $enrollment = Enrollment::with([
                'student',
                'classe.level',
                'academicYear',
                'financial',
                'validator'
            ])->findOrFail($id);

            $creatorUser = User::find($enrollment->created_by);
            $validatorUser = $enrollment->validator ?? User::find($enrollment->validated_by);

            $formatted = [
                'id'                => $enrollment->id,
                'enrollment_number' => $enrollment->enrollment_number,
                'type'              => $enrollment->type,
                'status'            => $enrollment->status,
                'enrollment_date'   => $enrollment->enrollment_date ? $enrollment->enrollment_date->format('Y-m-d') : null,
                'notes'             => $enrollment->notes,
                'created_at'        => Carbon::parse($enrollment->created_at)->toISOString(),
                'validated_at'      => $enrollment->validated_at ? Carbon::parse($enrollment->validated_at)->toISOString() : null,
                'created_by' => $creatorUser ? [
                    'id'   => $creatorUser->id,
                    'name' => $creatorUser->name,
                ] : null,
                'validated_by' => $validatorUser ? [
                    'id'   => $validatorUser->id,
                    'name' => $validatorUser->name,
                ] : null,
                'student' => $enrollment->student ? [
                    'id'          => $enrollment->student->id,
                    'matricule'   => $enrollment->student->matricule,
                    'first_name'  => $enrollment->student->first_name,
                    'last_name'   => $enrollment->student->last_name,
                    'gender'      => $enrollment->student->gender,
                    'photo'       => $enrollment->student->photo,
                    'birth_date'  => $enrollment->student->birth_date ? $enrollment->student->birth_date->format('Y-m-d') : null,
                    'birth_place' => $enrollment->student->birth_place,
                    'phone'       => $enrollment->student->phone,
                    'email'       => $enrollment->student->email,
                    'address'     => $enrollment->student->address,
                    'religion'    => $enrollment->student->religion,
                    'handicap'    => $enrollment->student->handicap,
                ] : null,
                'classe' => $enrollment->classe ? [
                    'name'  => $enrollment->classe->name,
                    'level' => $enrollment->classe->level ? $enrollment->classe->level->name : 'N/A',
                    'cycle' => ($enrollment->classe && $enrollment->classe->level) ? $enrollment->classe->level->name : 'N/A',
                ] : null,
                'academic_year' => [
                    'name' => $enrollment->academicYear ? $enrollment->academicYear->name : 'N/A'
                ],
                'financial' => $enrollment->financial ? [
                    'registration_fee' => (int) $enrollment->financial->registration_fee,
                    'tuition_fee'      => (int) $enrollment->financial->tuition_fee,
                    'annex_fee'        => (int) $enrollment->financial->annex_fee,
                    'discount_amount'  => (int) $enrollment->financial->discount_amount,
                    'total_due'        => (int) $enrollment->financial->total_due,
                    'initial_payment'  => (int) $enrollment->financial->initial_payment,
                    'payment_method'   => $enrollment->financial->payment_method,
                ] : [
                    'registration_fee' => 0, 'tuition_fee' => 0, 'annex_fee' => 0,
                    'discount_amount' => 0, 'total_due' => 0, 'initial_payment' => 0,
                    'payment_method' => 'none'
                ]
            ];

            return response()->json($formatted, 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Dossier d\'inscription introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur technique lors de la récupération.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    public function getStudentBalance(string $studentId)
    {
        $student = Student::find($studentId);

        if (!$student) {
            return response()->json([
                'status'  => 'error',
                'message' => "Élève introuvable."
            ], 404);
        }

        $lastEnrollment = Enrollment::withoutGlobalScope('viewingYear')
            ->where('student_id', $student->id)
            ->where('status', '!=', 'cancelled')
            ->with('academicYear')
            ->orderByDesc('academic_year_id')
            ->orderByDesc('id')
            ->first();

        $financial = null;
        if ($lastEnrollment) {
            $financial = EnrollmentFinancial::withoutGlobalScopes()
                ->where('enrollment_id', $lastEnrollment->id)
                ->first();
        }

        $previousBalance = 0;
        if ($financial) {
            $previousBalance = max(0, (int) $financial->total_due - (int) $financial->initial_payment);
        }

        return response()->json([
            'status'            => 'success',
            'previous_balance'  => $previousBalance,
            'previous_year'     => $lastEnrollment?->academicYear?->name,
        ]);
    }

    /**
     * Génère le reçu d'inscription imprimable — en HTML brut, appelé par le
     * frontend via un fetch() authentifié CLASSIQUE (en-tête Authorization
     * normal, comme n'importe quel autre appel API de l'application) — le
     * HTML renvoyé est ensuite injecté côté React dans un onglet déjà
     * ouvert, plutôt que de naviguer directement vers cette URL. Reste donc
     * protégée par le middleware auth:sanctum standard, sans contournement.
     *
     * Affiche systématiquement l'identité de l'établissement RÉELLEMENT
     * actif au moment de l'inscription (current_establishment_id()) — que
     * ce soit un établissement simple ou un établissement affilié d'un
     * groupe scolaire, le nom/logo/signature correspondent toujours à
     * l'établissement précis concerné, jamais à un "nom générique".
     *
     * URL : GET /enrollments/{id}/receipt
     */
    public function receiptPrint(string $id)
    {
        try {
            $enrollment = Enrollment::with(['student', 'classe.level', 'academicYear', 'financial'])
                ->findOrFail($id);

            $establishment = Establishment::find(current_establishment_id());
            $student = $enrollment->student;
            $financial = $enrollment->financial;

            $logoUrl = null;
            if ($establishment && $establishment->logo) {
                $logoUrl = str_starts_with($establishment->logo, 'http')
                    ? $establishment->logo
                    : url($establishment->logo);
            }

            // ─── AJOUT : photo de l'élève, même principe que le logo ───
            $studentPhotoUrl = null;
            if ($student && $student->photo) {
                $studentPhotoUrl = str_starts_with($student->photo, 'http')
                    ? $student->photo
                    : url($student->photo);
            }
            $studentInitials = strtoupper(
                mb_substr($student->first_name ?? '', 0, 1) . mb_substr($student->last_name ?? '', 0, 1)
            ) ?: '?';

            $totalDue       = $financial ? (int) $financial->total_due : 0;
            $initialPayment = $financial ? (int) $financial->initial_payment : 0;
            $remaining      = max(0, $totalDue - $initialPayment);

            $dateEmission = Carbon::parse($enrollment->enrollment_date ?? now())->format('d/m/Y');
            $directorTitle = $establishment->director_title ?? 'Directeur';
            $directorName  = $establishment->director_name ?? '';

            $methods = [
                'cash'   => 'Espèces / Caisse',
                'online' => 'Paiement en ligne / Mobile Money',
                'none'   => 'Aucun versement',
            ];
            $moyenPaiement = $methods[$financial?->payment_method] ?? '—';

            return "
            <!DOCTYPE html>
            <html lang='fr'>
            <head>
                <meta charset='UTF-8'>
                <title>Reçu d'inscription - {$enrollment->enrollment_number}</title>
                <style>
                    body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 13px; color: #222; background: #fff; padding: 20px; }
                    .receipt { width: 190mm; border: 2px solid #3D2E22; border-radius: 10px; padding: 25px; margin: 0 auto; box-sizing: border-box; }
                    .header { display: flex; align-items: center; gap: 14px; padding-bottom: 16px; margin-bottom: 20px; border-bottom: 3px double #3D2E22; }
                    .header img { width: 56px; height: 56px; object-fit: cover; border-radius: 10px; border: 1px solid #ddd; flex-shrink: 0; }
                    .header .school-name { font-size: 17pt; font-weight: 800; color: #3D2E22; margin: 0; }
                    .header .school-sub { font-size: 9pt; color: #999; margin: 3px 0 0; }
                    .receipt-title { text-align: center; margin: 10px 0 20px; }
                    .receipt-title h2 { font-size: 15pt; letter-spacing: 1px; color: #E67E22; margin: 0; }
                    .meta-row { display: flex; justify-content: space-between; font-size: 11pt; margin-bottom: 6px; }
                    .meta-row .label { font-weight: 700; color: #555; }
                    .section-title { font-size: 10.5pt; font-weight: 700; background: #FEF0E6; color: #3D2E22; padding: 6px 10px; border-left: 4px solid #E67E22; margin: 18px 0 10px; }
                    table.info-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
                    table.info-table td { padding: 6px 4px; font-size: 11pt; }
                    table.info-table td.label { font-weight: 700; color: #555; width: 35%; }
                    .student-identity-row { display: flex; gap: 16px; align-items: stretch; }
                    .student-photo-box { width: 64px; height: 64px; flex-shrink: 0; border-radius: 10px; border: 1px solid #ddd; background: #F5EFE9; display: flex; align-items: center; justify-content: center; overflow: hidden; }
                    .student-photo-box img { width: 100%; height: 100%; object-fit: cover; }
                    .student-photo-initials { font-size: 15pt; font-weight: 800; color: #BBA98A; }
                    table.fin-table { width: 100%; border-collapse: collapse; margin-top: 6px; }
                    table.fin-table th, table.fin-table td { border: 1px solid #ddd; padding: 8px 10px; font-size: 11pt; }
                    table.fin-table th { background: #FAF7F5; text-align: left; }
                    table.fin-table td { text-align: right; }
                    .total-row td { font-weight: 800; background: #F5EFE9; }
                    .remaining-row td { font-weight: 800; color: " . ($remaining > 0 ? "#C0395A" : "#27AE60") . "; font-size: 13pt; }
                    .legal-note { margin-top: 16px; font-size: 9.5pt; color: #888; font-style: italic; text-align: center; }
                    .signature-zone { margin-top: 50px; display: flex; justify-content: flex-end; }
                    .signature-box { text-align: center; width: 240px; }
                    .signature-box .role { font-weight: 700; font-size: 10.5pt; margin-bottom: 45px; }
                    .signature-box .name { font-size: 10pt; color: #555; border-top: 1px solid #999; padding-top: 6px; }
                    @media print {
                        body { padding: 0; }
                        .receipt { border: 2px solid #3D2E22; }
                    }
                </style>
            </head>
            <body onload='window.print();'>
                <div class='receipt'>

                    <div class='header'>
                        " . ($logoUrl ? "<img src='{$logoUrl}' alt='Logo' />" : "") . "
                        <div>
                            <p class='school-name'>" . strtoupper($establishment->name ?? 'DEXSCHOOL MANAGER') . "</p>
                            <p class='school-sub'>Généré via DexSchool Manager · " . ($establishment->address ?? '') . "</p>
                        </div>
                    </div>

                    <div class='receipt-title'>
                        <h2>REÇU D'INSCRIPTION</h2>
                    </div>

                    <div class='meta-row'>
                        <span><span class='label'>N° Inscription :</span> {$enrollment->enrollment_number}</span>
                        <span><span class='label'>Date :</span> {$dateEmission}</span>
                    </div>
                    <div class='meta-row'>
                        <span><span class='label'>Type :</span> " . ($enrollment->type === 'new' ? 'Nouvelle inscription' : 'Réinscription') . "</span>
                        <span><span class='label'>Année scolaire :</span> " . ($enrollment->academicYear->name ?? '—') . "</span>
                    </div>

                    <div class='section-title'>IDENTITÉ DE L'ÉLÈVE</div>
                    <div class='student-identity-row'>
                        <div class='student-photo-box'>
                            " . ($studentPhotoUrl
                                ? "<img src='{$studentPhotoUrl}' alt='Photo élève' />"
                                : "<span class='student-photo-initials'>{$studentInitials}</span>"
                            ) . "
                        </div>
                        <table class='info-table' style='flex: 1;'>
                            <tr>
                                <td class='label'>Nom & Prénoms :</td>
                                <td>" . strtoupper($student->last_name ?? '') . " " . ($student->first_name ?? '') . "</td>
                                <td class='label'>Matricule :</td>
                                <td>{$student->matricule}</td>
                            </tr>
                            <tr>
                                <td class='label'>Classe :</td>
                                <td>" . ($enrollment->classe->name ?? '—') . "</td>
                                <td class='label'>Genre :</td>
                                <td>" . ($student->gender === 'F' ? 'Féminin' : 'Masculin') . "</td>
                            </tr>
                        </table>
                    </div>

                    <div class='section-title'>DÉTAIL FINANCIER (FCFA)</div>
                    <table class='fin-table'>
                        <tr><th>Droit d'inscription</th><td>" . number_format($financial->registration_fee ?? 0, 0, '', ' ') . " FCFA</td></tr>
                        <tr><th>Scolarité annuelle</th><td>" . number_format($financial->tuition_fee ?? 0, 0, '', ' ') . " FCFA</td></tr>
                        <tr><th>Frais annexes</th><td>" . number_format($financial->annex_fee ?? 0, 0, '', ' ') . " FCFA</td></tr>
                        " . (($financial->previous_balance ?? 0) > 0 ? "<tr><th>Reliquat année précédente</th><td>" . number_format($financial->previous_balance, 0, '', ' ') . " FCFA</td></tr>" : "") . "
                        <tr><th>Réduction / Bourse</th><td>- " . number_format($financial->discount_amount ?? 0, 0, '', ' ') . " FCFA</td></tr>
                        <tr class='total-row'><th>TOTAL DÛ</th><td>{$totalDue} FCFA</td></tr>
                        <tr><th>Acompte versé (" . $moyenPaiement . ")</th><td>" . number_format($initialPayment, 0, '', ' ') . " FCFA</td></tr>
                        <tr class='remaining-row'><th>RESTE À PAYER</th><td>" . number_format($remaining, 0, '', ' ') . " FCFA</td></tr>
                    </table>

                    <div class='legal-note'>⚠️ Aucun remboursement possible sur les montants encaissés.</div>

                    <div class='signature-zone'>
                        <div class='signature-box'>
                            <div class='role'>Signature du comptable</div>
                        </div>
                    </div>

                </div>
            </body>
            </html>
            ";

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return "<html><body><p style='color:red; font-family:sans-serif; text-align:center; margin-top:50px;'>Erreur : Inscription introuvable.</p></body></html>";
        } catch (\Exception $e) {
            return "<html><body><p style='color:red; font-family:sans-serif;'>Erreur technique : " . $e->getMessage() . "</p></body></html>";
        }
    }
}