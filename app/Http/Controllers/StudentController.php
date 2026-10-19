<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateStudentRequest;
use App\Models\Academic\AcademicYears;
use App\Models\Academic\Classe;
use App\Models\Academic\Student;
use App\Models\officeAdministration\Enrollment;
use App\Models\officeAdministration\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StudentController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $viewingYearId = current_viewing_year_id();
            $activeYearId  = current_active_year_id();
            $isActiveYearView = ((int) $viewingYearId === (int) $activeYearId);

            $students = Student::with([
                    'parents',
                    'academicRecords' => function ($q) use ($viewingYearId) {
                        $q->where('academic_year_id', $viewingYearId);
                    },
                ])
                ->orderBy('created_at', 'desc')
                ->get();

            if ($isActiveYearView) {
                $students = $students->reject(function ($student) {
                    $lastDecision = \App\Models\Academic\StudentAcademicRecord::withoutGlobalScope('viewingYear')
                        ->where('student_id', $student->id)
                        ->orderByDesc('academic_year_id')
                        ->value('year_end_decision');
                    return $lastDecision === 'E';
                })->values();
            }

            $students->each(function ($student) use ($viewingYearId, $isActiveYearView) {
                $yearRecord = \App\Models\Academic\StudentAcademicRecord::withoutGlobalScope('viewingYear')
                    ->where('student_id', $student->id)
                    ->where('academic_year_id', $viewingYearId)
                    ->first();

                $yearClassId = $yearRecord?->class_id;

                if (!$yearClassId) {
                    $yearEnrollment = Enrollment::withoutGlobalScope('viewingYear')
                        ->where('student_id', $student->id)
                        ->where('academic_year_id', $viewingYearId)
                        ->where('status', '!=', 'cancelled')
                        ->orderByDesc('id')
                        ->first();
                    $yearClassId = $yearEnrollment?->class_id;
                }

                if ($yearClassId) {
                    $classe = Classe::withoutGlobalScope('viewingYear')->with('level.cycle')->find($yearClassId);
                    $student->setRelation('classe', $classe);
                    $student->class_id = $classe?->id;
                    $student->last_enrollment = null;
                } elseif ($isActiveYearView) {
                    $last = Enrollment::withoutGlobalScope('viewingYear')
                        ->where('student_id', $student->id)
                        ->where('status', '!=', 'cancelled')
                        ->orderByDesc('academic_year_id')->orderByDesc('id')
                        ->with([
                            'classe' => function ($q) {
                                $q->withoutGlobalScope('viewingYear')->with('level.cycle');
                            },
                            'academicYear',
                        ])
                        ->first();

                    $student->last_enrollment = $last ? [
                        'classe' => $last->classe ? [
                            'name'  => $last->classe->name,
                            'level' => $last->classe->level?->name,
                            'cycle' => $last->classe->level?->cycle?->name,
                        ] : null,
                        'academic_year' => $last->academicYear ? ['name' => $last->academicYear->name] : null,
                    ] : null;

                    $student->setRelation('classe', null);
                    $student->class_id = null;
                } else {
                    $student->setRelation('classe', null);
                    $student->class_id = null;
                    $student->last_enrollment = null;
                }
            });

            return response()->json([
                'status'  => 'success',
                'count'   => $students->count(),
                'data'    => $students
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Impossible de récupérer la liste des élèves.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    public function toggleStatus(Request $request, string $id)
    {
        assert_writable_year();
        $request->validate([
            'is_active' => 'required|boolean'
        ]);

        try {
            $student = Student::findOrFail($id);
            $student->update([
                'is_active' => $request->is_active
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Le statut de l\'élève a été mis à jour avec succès.',
                'data'    => [
                    'id'        => $student->id,
                    'is_active' => (bool)$student->is_active
                ]
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Élève introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la mise à jour du statut.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Enregistrer un nouvel élève
     * Route::post("/CreateStudents", [StudentController::class, "store"]);
     */
    public function store(\App\Http\Requests\StoreStudentRequest $request)
    {
        assert_writable_year();

        // ─── ANTI-DOUBLON : un élève déjà enregistré (même matricule national, ou
        // même nom + même date de naissance) ne peut pas être recréé, même dans
        // une autre classe. On renvoie l'endroit où il existe déjà. ───
        $found = $this->findSimilarStudents($request->only(
            'last_name', 'first_name', 'birth_date', 'national_matricule'
        ));
        if ($found['exact']->isNotEmpty()) {
            return response()->json([
                'status'     => 'error',
                'code'       => 'duplicate_student',
                'message'    => $this->duplicateMessage($found['exact']->first()),
                'duplicates' => $found['exact']->map(fn ($d) => $this->duplicateCard($d))->values(),
            ], 422);
        }

        if ($request->filled('class_id')) {
            $classId = $request->class_id;
            $academicYearId = $request->academic_year_id ?? current_active_year_id();
            if ($academicYearId) {
                $classe = Classe::findOrFail($classId);
                $maxCapacity = (int) $classe->capacity;

                $currentCount = Student::where('class_id', $classId)
                    ->where('academic_year_id', $academicYearId)
                    ->count();

                if ($currentCount >= $maxCapacity) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => "Création impossible : La classe '{$classe->name}' est saturée (limite fixée à {$maxCapacity} places). Veuillez affecter cet élève à un autre groupe disponible."
                    ], 422);
                }
            }
        }

        DB::beginTransaction();
        $photoPath = null;

        try {
            $estabPrefix = current_establishment_prefix();
            $yearShort   = current_school_year_short();
            $prefix      = "{$estabPrefix}-{$yearShort}-";

            $lastStudent = Student::withTrashed()
                ->where('matricule', 'LIKE', $prefix . '%')
                ->orderBy('matricule', 'desc')
                ->first();

            if ($lastStudent) {
                $lastSequence = (int) substr($lastStudent->matricule, -4);
                $newSequence = str_pad($lastSequence + 1, 4, '0', STR_PAD_LEFT);
            } else {
                $newSequence = '0001';
            }

            $matricule = $prefix . $newSequence;

            if ($request->hasFile('photo') && $request->file('photo')->isValid()) {
                $photoPath = $request->file('photo')->store('students_photos', 'public');
            }

            $student = Student::create([
                'matricule'             => $matricule,
                'last_name'             => strtoupper($request->last_name),
                'first_name'            => ucwords(strtolower($request->first_name)),
                'gender'                => $request->gender,
                'birth_date'            => $request->birth_date,
                'birth_place'           => $request->birth_place,
                'nationality'           => $request->nationality,
                'national_matricule'    => $request->national_matricule,
                'provisional_matricule' => $request->provisional_matricule,
                'origin_school'         => $request->origin_school,
                'phone'                 => $request->phone,
                'email'                 => $request->email,
                'address'               => $request->address,
                'religion'              => $request->religion,
                'handicap'              => $request->handicap,
                'class_id'              => $request->class_id,
                'academic_year_id'      => $request->academic_year_id,
                'photo'                 => $photoPath ? \Illuminate\Support\Facades\Storage::url($photoPath) : null,
                'is_active'             => filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN),
                'is_transferred'        => filter_var($request->is_transferred, FILTER_VALIDATE_BOOLEAN),
                'assignment_status'     => $request->assignment_status ?? 'non_affecte',
                'is_enrolled'           => false,
                'created_by'            => \Illuminate\Support\Facades\Auth::id() ?? null,
            ]);

            if ($request->filled('tutor_name')) {
                \App\Models\Academic\StudentParent::create([
                    'student_id'           => $student->id,
                    'type'                 => 'guardian',
                    'last_name'            => $request->tutor_name,
                    'first_name'           => '',
                    'phone'                => $request->tutor_phone ?: '0000000000',
                    'is_main_contact'      => true,
                    'is_emergency_contact' => true,
                ]);
            }

            if ($request->filled('academic_year_id')) {
                \App\Models\Academic\StudentAcademicRecord::create([
                    'student_id'        => $student->id,
                    'academic_year_id'  => $request->academic_year_id,
                    'class_id'          => $request->class_id,
                    'lv2'               => $request->lv2,
                    'art'               => $request->art,
                    'is_repeater'       => filter_var($request->is_repeater, FILTER_VALIDATE_BOOLEAN),
                ]);
            }

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'L\'élève ' . $student->first_name . ' ' . $student->last_name . ' a été créé avec succès avec le matricule ' . $student->matricule,
                'data'    => $student
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            if ($photoPath) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($photoPath);
            }

            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de l\'enregistrement de l\'élève en base de données.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer les détails d'un élève spécifique
     * Route::get("/students/{id}", [StudentController::class, "show"]);
     */
    public function show(Request $request, string $id)
    {
        try {
            $viewingYearId = current_viewing_year_id();
            $activeYearId  = current_active_year_id();
            $isActiveYearView = ((int) $viewingYearId === (int) $activeYearId);

            $student = Student::with(['parents'])->findOrFail($id);

            $currentRecord = \App\Models\Academic\StudentAcademicRecord::withoutGlobalScope('viewingYear')
                ->where('student_id', $student->id)
                ->where('academic_year_id', $viewingYearId)
                ->first();

            // ─── CORRECTIF CRITIQUE : c'est CETTE requête qui causait le
            // décalage signalé — sans filtre ni tri, elle pouvait renvoyer
            // n'importe quelle inscription (y compris une ANNULÉE) pour cet
            // élève/année, au lieu de LA vraie inscription active. Résultat :
            // le dossier élève affichait les infos financières et le numéro
            // d'une inscription périmée, complètement décorrélées du vrai
            // dossier d'inscription actuellement actif. ───
            $yearEnrollment = Enrollment::withoutGlobalScope('viewingYear')
                ->where('student_id', $student->id)
                ->where('academic_year_id', $viewingYearId)
                ->where('status', '!=', 'cancelled')
                ->orderByDesc('id')
                ->first();

            $yearClassId = $currentRecord?->class_id ?? $yearEnrollment?->class_id;
            $classe = $yearClassId
                ? Classe::withoutGlobalScope('viewingYear')->with('level.cycle')->find($yearClassId)
                : null;

            $lastEnrollmentInfo = null;
            if (!$classe && $isActiveYearView) {
                $last = Enrollment::withoutGlobalScope('viewingYear')
                    ->where('student_id', $student->id)
                    ->where('status', '!=', 'cancelled')
                    ->orderByDesc('academic_year_id')->orderByDesc('id')
                    ->with([
                        'classe' => function ($q) {
                            $q->withoutGlobalScope('viewingYear')->with('level.cycle');
                        },
                        'academicYear',
                    ])
                    ->first();

                if ($last) {
                    $lastEnrollmentInfo = [
                        'classe' => $last->classe ? [
                            'name'  => $last->classe->name,
                            'level' => $last->classe->level?->name,
                            'cycle' => $last->classe->level?->cycle?->name,
                        ] : null,
                        'academic_year' => $last->academicYear ? ['name' => $last->academicYear->name] : null,
                    ];
                }
            }

            $financial = $yearEnrollment
                ? \App\Models\officeAdministration\EnrollmentFinancial::withoutGlobalScopes()
                    ->where('enrollment_id', $yearEnrollment->id)->first()
                : null;

            $totalDue       = $financial ? (int) $financial->total_due : 0;
            $initialPayment = $financial ? (int) $financial->initial_payment : 0;
            $remaining      = max(0, $totalDue - $initialPayment);

            $financialSummary = [
                'total_due'  => $totalDue,
                'total_paid' => $initialPayment,
                'remaining'  => $remaining,
            ];

            // ─── HISTORIQUE COMPLET DES PAIEMENTS, PAS JUSTE L'ACOMPTE ───
            $paymentsHistory = [];

            if ($yearEnrollment) {
                $cashPayments = Payment::where('enrollment_id', $yearEnrollment->id)
                    ->orderBy('payment_date')
                    ->orderBy('id')
                    ->get();

                $cashPaymentsSum = (int) $cashPayments->sum('amount_paid');
                $enrollmentDeposit = max(0, $initialPayment - $cashPaymentsSum);

                if ($enrollmentDeposit > 0) {
                    $paymentsHistory[] = [
                        'id'     => 'init_' . $yearEnrollment->id,
                        'label'  => 'Acompte initial — Inscription ' . $yearEnrollment->enrollment_number,
                        'amount' => $enrollmentDeposit,
                        'date'   => $yearEnrollment->enrollment_date ? $yearEnrollment->enrollment_date->format('Y-m-d') : null,
                        'method' => $financial?->payment_method,
                        'status' => 'paid',
                    ];
                }

                foreach ($cashPayments as $p) {
                    $paymentsHistory[] = [
                        'id'     => $p->id,
                        'label'  => 'Versement — Reçu ' . $p->receipt_number,
                        'amount' => (int) $p->amount_paid,
                        'date'   => $p->payment_date ? $p->payment_date->format('Y-m-d') : null,
                        'method' => $p->payment_method,
                        'status' => 'paid',
                    ];
                }
            }

            $studentData = $student->toArray();
            $studentData['classe']            = $classe;
            $studentData['last_enrollment']   = $lastEnrollmentInfo;
            $studentData['financial_summary'] = $financialSummary;
            $studentData['payments']          = $paymentsHistory;

            $viewingYear = \App\Models\Academic\AcademicYears::find($viewingYearId);
            $studentData['viewing_academic_year'] = $viewingYear ? [
                'id'   => $viewingYear->id,
                'name' => $viewingYear->name,
            ] : null;

            return response()->json([
                "status"          => "success",
                "donnees_eleve"   => $studentData,
                "student"         => $studentData,
                "current_record"  => $currentRecord,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                "status"  => "error",
                "message" => "Élève introuvable ou n'appartenant pas à votre établissement."
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                "status"  => "error",
                "message" => "Une erreur est survenue lors du chargement du dossier.",
                "debug"   => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        assert_writable_year();
        try {
            $student = Student::findOrFail($id);

            $student->update([
                'updated_by' => Auth::id() ?? null
            ]);

            $student->delete();

            return response()->json([
                'status'  => 'success',
                'message' => 'Le dossier de l\'élève ' . $student->first_name . ' ' . $student->last_name . ' a été archivé avec succès.'
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Élève introuvable ou déjà supprimé.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la suppression du dossier.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    public function trashed()
    {
        $students = Student::onlyTrashed()->get();

        return response()->json([
            'status' => 'success',
            'data'   => $students
        ], 200);
    }

    public function restore(string $id)
    {
        assert_writable_year();
        try {
            $student = Student::onlyTrashed()->findOrFail($id);
            $student->restore();

            return response()->json([
                'status'  => 'success',
                'message' => 'Le dossier de l\'élève ' . $student->first_name . ' ' . $student->last_name . ' a été restauré avec succès.'
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Cet élève n\'existe pas dans la corbeille.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la restauration.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    public function restoreAll()
    {
        assert_writable_year();
        try {
            $count = Student::onlyTrashed()->restore();

            return response()->json([
                'status'  => 'success',
                'message' => $count . ' élève(s) ont été restauré(s) avec succès de la corbeille.'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la restauration globale de la corbeille.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mettre à jour le dossier d'un élève
     * Route::post("/students/{id}/update", [StudentController::class, "update"]);
     */
    public function update(UpdateStudentRequest $request, string $id)
    {
        assert_writable_year();

        // Contrôle anti-doublon seulement si l'IDENTITÉ change (nom, naissance, matricule),
        // pour ne pas bloquer la correction d'une fiche déjà en doublon.
        $current = Student::find($id);
        if ($current) {
            $newBirth = $request->filled('birth_date') ? substr((string) $request->birth_date, 0, 10) : null;
            $oldBirth = $current->birth_date ? $current->birth_date->format('Y-m-d') : null;
            $identityChanged =
                $this->nameKey($request->last_name, $request->first_name) !== $this->nameKey($current->last_name, $current->first_name)
                || $newBirth !== $oldBirth
                || $this->normMatricule($request->national_matricule) !== $this->normMatricule($current->national_matricule);

            if ($identityChanged) {
                $found = $this->findSimilarStudents($request->only(
                    'last_name', 'first_name', 'birth_date', 'national_matricule'
                ), (int) $id);
                if ($found['exact']->isNotEmpty()) {
                    return response()->json([
                        'status'     => 'error',
                        'code'       => 'duplicate_student',
                        'message'    => $this->duplicateMessage($found['exact']->first()),
                        'duplicates' => $found['exact']->map(fn ($d) => $this->duplicateCard($d))->values(),
                    ], 422);
                }
            }
        }

        \Illuminate\Support\Facades\DB::beginTransaction();

        try {
            $student = \App\Models\Academic\Student::findOrFail($id);

            $photoPath = $student->getRawOriginal('photo');

            if ($request->hasFile('photo') && $request->file('photo')->isValid()) {
                if ($student->photo) {
                    $oldFile = str_replace('/storage/', '', $student->photo);
                    if (\Illuminate\Support\Facades\Storage::disk('public')->exists($oldFile)) {
                        \Illuminate\Support\Facades\Storage::disk('public')->delete($oldFile);
                    }
                }
                $photoPath = $request->file('photo')->store('students_photos', 'public');
            }

            $student->update([
                'last_name'             => strtoupper($request->last_name),
                'first_name'            => ucwords(strtolower($request->first_name)),
                'gender'                => $request->gender,
                'birth_date'            => $request->birth_date,
                'birth_place'           => $request->birth_place,
                'nationality'           => $request->nationality,
                'national_matricule'    => $request->national_matricule,
                'provisional_matricule' => $request->provisional_matricule,
                'origin_school'         => $request->origin_school,
                'phone'                 => $request->phone,
                'email'                 => $request->email,
                'address'               => $request->address,
                'religion'              => $request->religion,
                'handicap'              => $request->handicap,
                'class_id'              => $request->class_id,
                'academic_year_id'      => $request->academic_year_id,
                'is_transferred'        => filter_var($request->is_transferred, FILTER_VALIDATE_BOOLEAN),
                'assignment_status'     => $request->assignment_status ?? 'non_affecte',
                'photo'                 => $request->hasFile('photo') ? \Illuminate\Support\Facades\Storage::url($photoPath) : $student->photo,
                'is_active'             => filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN),
                'updated_by'            => \Illuminate\Support\Facades\Auth::id() ?? null,
            ]);

            $activeYearId = current_active_year_id();
            if ($activeYearId) {
                \App\Models\Academic\StudentAcademicRecord::updateOrCreate(
                    [
                        'student_id'       => $student->id,
                        'academic_year_id' => $activeYearId,
                    ],
                    [
                        'class_id'    => $request->class_id,
                        'lv2'         => $request->lv2,
                        'art'         => $request->art,
                        'is_repeater' => filter_var($request->is_repeater, FILTER_VALIDATE_BOOLEAN),
                    ]
                );
            }

            if (!empty($request->tutor_name)) {
                // ─── Le formulaire édite le parent CONTACT PRINCIPAL (père, mère
                // ou tuteur), identifié par tutor_id — plus seulement le "guardian".
                // On met à jour CE parent précis, sans écraser son prénom ni
                // son type, et sans toucher aux autres champs (email, adresse...).
                $tutorId = $request->input('tutor_id');
                $existingParent = $tutorId
                    ? \App\Models\Academic\StudentParent::where('student_id', $student->id)->find($tutorId)
                    : null;

                if ($existingParent) {
                    $existingParent->update([
                        'last_name'  => $request->tutor_name,
                        'first_name' => $request->input('tutor_first_name', $existingParent->first_name) ?? '',
                        'phone'      => $request->tutor_phone ?: $existingParent->phone,
                        'profession' => $request->filled('tutor_profession') ? $request->tutor_profession : null,
                    ]);
                } else {
                    // Aucun parent existant : création d'un tuteur, contact principal
                    \App\Models\Academic\StudentParent::where('student_id', $student->id)
                        ->update(['is_main_contact' => false]);

                    \App\Models\Academic\StudentParent::create([
                        'student_id'      => $student->id,
                        'type'            => 'guardian',
                        'last_name'       => $request->tutor_name,
                        'first_name'      => $request->input('tutor_first_name', '') ?? '',
                        'phone'           => $request->tutor_phone ?: '0000000000',
                        'profession'      => $request->filled('tutor_profession') ? $request->tutor_profession : null,
                        'is_main_contact' => true,
                    ]);
                }
            }

            \Illuminate\Support\Facades\DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Le dossier de l\'élève ' . $student->first_name . ' ' . $student->last_name . ' a été mis à jour avec succès.',
                'data'    => $student->fresh(['classe', 'academicYear', 'parents', 'academicRecords']),
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => 'Élève introuvable.'
            ], 404);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la mise à jour.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    //  ANTI-DOUBLONS
    // ═════════════════════════════════════════════════════════════════════

    /** Minuscules, sans accents, sans ponctuation, espaces normalisés. */
    private function normName(?string $v): string
    {
        $v = mb_strtolower(trim((string) $v), 'UTF-8');
        $v = \Illuminate\Support\Str::ascii($v);
        $v = preg_replace('/[^a-z0-9]+/', ' ', $v);
        return trim(preg_replace('/\s+/', ' ', $v));
    }

    /**
     * Clé d'identité d'un nom : tokens triés, donc insensible à l'ordre
     * (« KOUASSI Jean » = « Jean KOUASSI ») et aux accents/majuscules.
     */
    private function nameKey(?string $last, ?string $first): string
    {
        $tokens = array_filter(explode(' ', $this->normName($last) . ' ' . $this->normName($first)));
        sort($tokens);
        return implode(' ', $tokens);
    }

    private function normMatricule(?string $v): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $v));
    }

    /**
     * Cherche les élèves (non supprimés, de l'établissement courant) qui
     * ressemblent à l'identité fournie.
     *  - 'exact'    : même matricule national, OU même nom + même date de naissance
     *  - 'probable' : même nom, date de naissance différente ou inconnue
     * Retourne ['exact' => Collection<Student>, 'probable' => Collection<Student>]
     */
    private function findSimilarStudents(array $attrs, ?int $excludeId = null): array
    {
        $key       = $this->nameKey($attrs['last_name'] ?? '', $attrs['first_name'] ?? '');
        $matricule = $this->normMatricule($attrs['national_matricule'] ?? '');
        $birth     = !empty($attrs['birth_date']) ? substr((string) $attrs['birth_date'], 0, 10) : null;

        $exact = collect();
        $probable = collect();

        if ($key === '' && $matricule === '') {
            return ['exact' => $exact, 'probable' => $probable];
        }

        // Pré-filtre SQL large (même nom de famille OU même date OU même matricule),
        // la comparaison fine (accents, ordre des mots) est faite en PHP.
        $lastLower = mb_strtolower(trim((string) ($attrs['last_name'] ?? '')), 'UTF-8');

        $candidates = Student::query()
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->where(function ($q) use ($lastLower, $birth, $matricule) {
                if ($lastLower !== '') $q->orWhereRaw('LOWER(last_name) LIKE ?', ['%' . $lastLower . '%']);
                if ($birth)            $q->orWhere('birth_date', $birth);
                if ($matricule !== '') $q->orWhereRaw("REPLACE(UPPER(national_matricule), ' ', '') = ?", [$matricule]);
            })
            ->with('classe')
            ->get();

        foreach ($candidates as $c) {
            $sameMatricule = $matricule !== '' && $this->normMatricule($c->national_matricule) === $matricule;
            $sameName      = $key !== '' && $this->nameKey($c->last_name, $c->first_name) === $key;
            $cBirth        = $c->birth_date ? $c->birth_date->format('Y-m-d') : null;
            $sameBirth     = $birth && $cBirth && $birth === $cBirth;

            if ($sameMatricule || ($sameName && $sameBirth)) {
                $exact->push($c);
            } elseif ($sameName) {
                $probable->push($c);
            }
        }

        return ['exact' => $exact->values(), 'probable' => $probable->values()];
    }

    /** Résumé d'un élève pour l'affichage des doublons. */
    private function duplicateCard(Student $s): array
    {
        $activeEnrollments = Enrollment::withoutGlobalScope('viewingYear')
            ->where('student_id', $s->id)
            ->where('status', '!=', 'cancelled')
            ->with('classe')
            ->orderByDesc('academic_year_id')->orderByDesc('id')
            ->get();

        $lastEnr = $activeEnrollments->first();
        $className = $s->classe?->name ?? $lastEnr?->classe?->name;

        return [
            'id'                 => $s->id,
            'matricule'          => $s->matricule,
            'national_matricule' => $s->national_matricule,
            'last_name'          => $s->last_name,
            'first_name'         => $s->first_name,
            'gender'             => $s->gender,
            'birth_date'         => $s->birth_date ? $s->birth_date->format('Y-m-d') : null,
            'birth_place'        => $s->birth_place,
            'class_name'         => $className,
            'is_enrolled'        => (bool) $s->is_enrolled,
            'enrollments_count'  => $activeEnrollments->count(),
            'enrollment_number'  => $lastEnr?->enrollment_number,
            'created_at'         => $s->created_at ? $s->created_at->toISOString() : null,
        ];
    }

    /** Message lisible : où l'élève existe déjà. */
    private function duplicateMessage(Student $s): string
    {
        $card = $this->duplicateCard($s);
        $where = $card['class_name']
            ? "et est déjà affecté(e) dans la classe {$card['class_name']}"
            : "(dossier existant, sans classe pour le moment)";
        return "Cet élève existe déjà : {$card['last_name']} {$card['first_name']} — matricule {$card['matricule']} {$where}. "
             . "Utilisez ce dossier (inscription ou transfert) au lieu d'en créer un nouveau.";
    }

    /**
     * Pré-contrôle appelé par le formulaire de création, avant l'enregistrement.
     * GET /students/check-duplicate?last_name=&first_name=&birth_date=&national_matricule=
     */
    public function checkDuplicate(Request $request)
    {
        $found = $this->findSimilarStudents($request->only(
            'last_name', 'first_name', 'birth_date', 'national_matricule'
        ), $request->filled('exclude_id') ? (int) $request->exclude_id : null);

        return response()->json([
            'status'   => 'success',
            'exact'    => $found['exact']->map(fn ($s) => $this->duplicateCard($s))->values(),
            'probable' => $found['probable']->take(5)->map(fn ($s) => $this->duplicateCard($s))->values(),
        ]);
    }

    /**
     * Liste des groupes de doublons de l'établissement.
     * Regroupe par matricule national OU par (identité du nom + date de naissance).
     * GET /students/duplicates
     */
    public function duplicates()
    {
        $students = Student::with('classe')->orderBy('id')->get();

        // Union-find simple : deux fiches liées par matricule OU par nom+naissance
        // finissent dans le même groupe.
        $parent = [];
        $find = function ($x) use (&$parent, &$find) {
            while ($parent[$x] !== $x) { $parent[$x] = $parent[$parent[$x]]; $x = $parent[$x]; }
            return $x;
        };
        foreach ($students as $s) $parent[$s->id] = $s->id;

        $byMatricule = [];
        $byIdentity  = [];
        foreach ($students as $s) {
            $m = $this->normMatricule($s->national_matricule);
            if ($m !== '') $byMatricule[$m][] = $s->id;

            $nk = $this->nameKey($s->last_name, $s->first_name);
            $bd = $s->birth_date ? $s->birth_date->format('Y-m-d') : null;
            if ($nk !== '' && $bd) $byIdentity[$nk . '|' . $bd][] = $s->id;
        }
        $reasons = [];
        foreach ([[$byMatricule, 'Même matricule national'], [$byIdentity, 'Même nom et même date de naissance']] as [$map, $label]) {
            foreach ($map as $ids) {
                if (count($ids) < 2) continue;
                $root = $find($ids[0]);
                foreach ($ids as $id) {
                    $r = $find($id);
                    if ($r !== $root) { $parent[$r] = $root; }
                }
                $reasons[$find($ids[0])][$label] = true;
            }
        }

        $groups = [];
        foreach ($students as $s) $groups[$find($s->id)][] = $s;

        $out = [];
        foreach ($groups as $members) {
            if (count($members) < 2) continue;
            $cards = array_map(fn ($s) => $this->duplicateCard($s), $members);

            // Quelles fiches peut-on supprimer sans rien perdre ?
            foreach ($cards as &$card) {
                $card['blockers'] = $this->deletionBlockers($card['id']);
            }
            unset($card);

            // Fiche à conserver suggérée : celle qui porte une inscription / une classe, sinon la plus ancienne.
            usort($cards, fn ($a, $b) =>
                [$b['enrollments_count'], $b['class_name'] ? 1 : 0, -$b['id']] <=> [$a['enrollments_count'], $a['class_name'] ? 1 : 0, -$a['id']]);

            $rootKey = $find($members[0]->id);
            $out[] = [
                'reason'            => implode(' · ', array_keys($reasons[$rootKey] ?? [])) ?: 'Fiches similaires',
                'suggested_keep_id' => $cards[0]['id'],
                'students'          => $cards,
            ];
        }

        return response()->json(['status' => 'success', 'count' => count($out), 'groups' => $out]);
    }

    /** Ce qui empêche de supprimer une fiche sans perdre de données. */
    private function deletionBlockers(int $studentId): array
    {
        $b = [];
        $enr = Enrollment::withoutGlobalScope('viewingYear')
            ->where('student_id', $studentId)->where('status', '!=', 'cancelled')->count();
        if ($enr) $b[] = "{$enr} inscription(s) active(s)";

        $student = Student::find($studentId);
        if (!$student) return $b;

        $checks = [
            'canteenSubscriptions'  => 'abonnement(s) cantine',
            'transportSubscriptions' => 'abonnement(s) transport',
            'grades'                => 'note(s)',
        ];
        foreach ($checks as $rel => $label) {
            try {
                $n = $student->{$rel}()->count();
                if ($n) $b[] = "{$n} {$label}";
            } catch (\Throwable $e) {
                // Relation/table indisponible : on ne bloque pas sur un contrôle impossible.
            }
        }
        return $b;
    }

    /**
     * Supprime (archive) une fiche en doublon. La fiche est mise en corbeille
     * (soft delete), donc restaurable depuis la liste des élèves supprimés.
     * POST /students/duplicates/resolve   { keep_id, remove_id }
     */
    public function resolveDuplicate(Request $request)
    {
        assert_writable_year();

        $data = $request->validate([
            'keep_id'   => ['required', 'integer', 'different:remove_id'],
            'remove_id' => ['required', 'integer'],
        ]);

        $keep   = Student::find($data['keep_id']);
        $remove = Student::find($data['remove_id']);

        if (!$keep || !$remove) {
            return response()->json(['status' => 'error', 'message' => 'Fiche introuvable.'], 404);
        }

        // Garde-fou : on ne supprime que si les deux fiches SONT bien des doublons.
        $found = $this->findSimilarStudents([
            'last_name' => $remove->last_name, 'first_name' => $remove->first_name,
            'birth_date' => $remove->birth_date ? $remove->birth_date->format('Y-m-d') : null,
            'national_matricule' => $remove->national_matricule,
        ], $remove->id);

        if (!$found['exact']->contains('id', $keep->id)) {
            return response()->json([
                'status'  => 'error',
                'message' => "Ces deux fiches ne sont pas des doublons certains (même matricule, ou même nom et même date de naissance).",
            ], 422);
        }

        $blockers = $this->deletionBlockers($remove->id);
        if (!empty($blockers)) {
            return response()->json([
                'status'  => 'error',
                'message' => "Cette fiche ne peut pas être supprimée : elle porte " . implode(', ', $blockers)
                           . ". Conservez plutôt cette fiche et supprimez l'autre.",
            ], 422);
        }

        DB::transaction(function () use ($remove) {
            $remove->update(['updated_by' => Auth::id() ?? null]);
            $remove->delete();
        });

        return response()->json([
            'status'  => 'success',
            'message' => "Le doublon {$remove->last_name} {$remove->first_name} ({$remove->matricule}) a été supprimé. Il reste récupérable dans la corbeille.",
        ]);
    }

    public function search(Request $request)
    {
        $query = $request->query('q');
        $enrolledOnly = $request->query('enrolled_only') === 'true';

        if (strlen($query) < 2) {
            return response()->json([], 200);
        }

        $establishmentId = current_establishment_id();
        $activeYearId = current_active_year_id();

        $nextYearId = \App\Models\Academic\AcademicYears::where('establishment_id', $establishmentId)
            ->where('is_active', 0)
            ->where('is_archived', 0)
            ->where('start_date', '>', now())
            ->orderBy('start_date', 'asc')
            ->value('id') ?? $activeYearId;

        $studentsQuery = Student::with([
                'classe',
                'academicRecords' => function ($q) use ($activeYearId) {
                    $q->where('academic_year_id', $activeYearId);
                },
            ])
            ->where('is_active', 1)
            ->where(function ($q) use ($query) {
                $q->where('first_name', 'LIKE', "%{$query}%")
                ->orWhere('last_name', 'LIKE', "%{$query}%")
                ->orWhere('matricule', 'LIKE', "%{$query}%");
            });

        if ($enrolledOnly) {
            $studentsQuery->whereNotNull('class_id')
                // ─── CORRECTIF : une inscription annulée ne doit plus
                // compter comme "déjà inscrit" — sinon l'élève reste
                // bloqué au clic dans la recherche, même après annulation. ───
                ->withExists(['enrollments' => function ($q) use ($nextYearId) {
                    $q->where('academic_year_id', $nextYearId)
                      ->where('status', '!=', 'cancelled');
                }]);
        } else {
            $studentsQuery->withExists(['enrollments' => function ($q) use ($activeYearId) {
                    $q->where('academic_year_id', $activeYearId)
                      ->where('status', '!=', 'cancelled');
                }])
                ->withCount(['enrollments' => function ($q) {
                    // ─── CORRECTIF : une inscription annulée ne doit
                    // JAMAIS compter comme historique réel — sinon un élève
                    // dont la SEULE inscription passée a été annulée serait
                    // à tort étiqueté "ancien élève" et redirigé vers le
                    // module Réinscription, alors qu'il n'a en réalité
                    // jamais été inscrit nulle part. ───
                    $q->withoutGlobalScope('viewingYear')
                      ->where('status', '!=', 'cancelled');
                }]);
        }

        $students = $studentsQuery->take(20)->get();

        $students = $students->reject(function ($student) {
            $lastDecision = \App\Models\Academic\StudentAcademicRecord::withoutGlobalScope('viewingYear')
                ->where('student_id', $student->id)
                ->orderByDesc('academic_year_id')
                ->value('year_end_decision');

            return $lastDecision === 'E';
        })->take(10)->values();

        $formatted = $students->map(function ($student) use ($enrolledOnly) {
            $lastEnrollment = \App\Models\officeAdministration\Enrollment::withoutGlobalScope('viewingYear')
                ->where('student_id', $student->id)
                ->where('status', '!=', 'cancelled')
                ->with([
                    'classe' => function ($q) {
                        $q->withoutGlobalScope('viewingYear')->with('level.cycle');
                    },
                    'academicYear',
                ])
                ->orderByDesc('academic_year_id')
                ->orderByDesc('id')
                ->first();

            $financial = null;
            if ($lastEnrollment) {
                $financial = \App\Models\officeAdministration\EnrollmentFinancial::withoutGlobalScopes()
                    ->where('enrollment_id', $lastEnrollment->id)
                    ->first();
            }

            $totalDue = $financial ? (int) $financial->total_due : 0;
            $totalPaid = $financial ? (int) $financial->initial_payment : 0;
            $soldeRestant = max(0, $totalDue - $totalPaid);
            $hasDebt = $soldeRestant > 0;

            $record = $student->academicRecords->first();
            $yearEndDecision = $record?->year_end_decision;

            $lastEnrollmentInfo = $lastEnrollment ? [
                'classe' => $lastEnrollment->classe ? [
                    'name'  => $lastEnrollment->classe->name,
                    'level' => $lastEnrollment->classe->level?->name,
                    'cycle' => $lastEnrollment->classe->level?->cycle?->name,
                ] : null,
                'academic_year' => $lastEnrollment->academicYear ? [
                    'name' => $lastEnrollment->academicYear->name,
                ] : null,
            ] : null;

            return [
                'id'               => $student->id,
                'matricule'        => $student->matricule,
                'first_name'       => $student->first_name,
                'last_name'        => $student->last_name,
                'gender'           => $student->gender,
                'class_id'         => $student->class_id,
                'academic_year_id' => $student->academic_year_id,
                'assignment_status' => $student->assignment_status ?? 'non_affecte',
                'is_enrolled'       => (bool) $student->is_enrolled,
                'classe'           => $student->classe,
                'last_enrollment'  => $lastEnrollmentInfo,

                'enrollments_exists' => $student->enrollments_exists,
                'enrollments_count'  => $student->enrollments_count ?? 0,

                'current_class'                => $student->classe ? $student->classe->name : 'N/A',
                'cycle'                        => ($student->classe && $student->classe->level) ? $student->classe->level->name : 'N/A',
                'enrollments_exists_next_year' => $enrolledOnly ? $student->enrollments_exists : false,

                'has_debt'     => $hasDebt,
                'debt_amount'  => $soldeRestant,

                'year_end_decision' => $yearEndDecision,
                'current_level_id'  => $student->classe?->level_id,
            ];
        });

        return response()->json($formatted->values(), 200);
    }
}