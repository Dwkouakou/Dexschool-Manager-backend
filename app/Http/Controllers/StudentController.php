<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateStudentRequest;
use App\Models\Academic\AcademicYears;
use App\Models\Academic\Classe;
use App\Models\Academic\Student;
use App\Models\officeAdministration\Enrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StudentController extends Controller
{





//     public function index(): JsonResponse
// {
//     try {
//         $viewingYearId = current_viewing_year_id();

//         $students = Student::with([
//                 'classe' => function ($q) {
//                     $q->withoutGlobalScope('viewingYear')->with('level.cycle');
//                 },
//                 'academicYear',
//                 'parents',
//                 'academicRecords' => function ($q) use ($viewingYearId) {
//                     $q->where('academic_year_id', $viewingYearId);
//                 },
//             ])
//             ->orderBy('created_at', 'desc')
//             ->get();

//         // ─── Exclusion DFA="E" : on prend le DERNIER DFA connu de chaque
//         // élève, peu importe l'année active du moment. On doit contourner
//         // le scope 'viewingYear' de StudentAcademicRecord, sinon la requête
//         // ne cherche que dans l'année consultée et ne trouve jamais le "E"
//         // enregistré sur une année précédente.
//         $students = $students->reject(function ($student) {
//             $lastDecision = \App\Models\Academic\StudentAcademicRecord::withoutGlobalScope('viewingYear')
//                 ->where('student_id', $student->id)
//                 ->orderByDesc('academic_year_id')
//                 ->value('year_end_decision');

//             return $lastDecision === 'E';
//         })->values();

//         $students->each(function ($student) use ($viewingYearId) {
//             $isCurrentYear = (int) $student->academic_year_id === (int) $viewingYearId;

//             if (!$isCurrentYear && $student->classe) {
//                 $student->last_enrollment = [
//                     'classe' => [
//                         'name'  => $student->classe->name,
//                         'level' => $student->classe->level?->name,
//                         'cycle' => $student->classe->level?->cycle?->name,
//                     ],
//                     'academic_year' => [
//                         'name' => $student->academicYear?->name,
//                     ],
//                 ];
//                 $student->setRelation('classe', null);
//             } else {
//                 $student->last_enrollment = null;
//             }
//         });

//         return response()->json([
//             'status'  => 'success',
//             'count'   => $students->count(),
//             'data'    => $students
//         ], 200);

//     } catch (\Exception $e) {
//         return response()->json([
//             'status'  => 'error',
//             'message' => 'Impossible de récupérer la liste des élèves.',
//             'debug'   => $e->getMessage()
//         ], 500);
//     }
// }

//     public function index(): JsonResponse
// {
//     try {
//         $viewingYearId = current_viewing_year_id();
//         $activeYearId = current_active_year_id();

//         // On n'exclut les DFA="E" QUE lorsque l'utilisateur consulte
//         // l'année réellement active (usage opérationnel courant).
//         // En consultation d'une ancienne année (archive), l'élève exclu
//         // reste visible : c'est un fait historique légitime, pas une
//         // action en cours qu'il faudrait bloquer.
//         $isViewingActiveYear = ((int) $viewingYearId === (int) $activeYearId);

//         $students = Student::with([
//                 'classe' => function ($q) {
//                     $q->withoutGlobalScope('viewingYear')->with('level.cycle');
//                 },
//                 'academicYear',
//                 'parents',
//                 'academicRecords' => function ($q) use ($viewingYearId) {
//                     $q->where('academic_year_id', $viewingYearId);
//                 },
//             ])
//             ->orderBy('created_at', 'desc')
//             ->get();

//         if ($isViewingActiveYear) {
//             $students = $students->reject(function ($student) {
//                 $lastDecision = \App\Models\Academic\StudentAcademicRecord::withoutGlobalScope('viewingYear')
//                     ->where('student_id', $student->id)
//                     ->orderByDesc('academic_year_id')
//                     ->value('year_end_decision');

//                 return $lastDecision === 'E';
//             })->values();
//         }

//         $students->each(function ($student) use ($viewingYearId) {
//             $isCurrentYear = (int) $student->academic_year_id === (int) $viewingYearId;

//             if (!$isCurrentYear && $student->classe) {
//                 $student->last_enrollment = [
//                     'classe' => [
//                         'name'  => $student->classe->name,
//                         'level' => $student->classe->level?->name,
//                         'cycle' => $student->classe->level?->cycle?->name,
//                     ],
//                     'academic_year' => [
//                         'name' => $student->academicYear?->name,
//                     ],
//                 ];
//                 $student->setRelation('classe', null);
//             } else {
//                 $student->last_enrollment = null;
//             }
//         });

//         return response()->json([
//             'status'  => 'success',
//             'count'   => $students->count(),
//             'data'    => $students
//         ], 200);

//     } catch (\Exception $e) {
//         return response()->json([
//             'status'  => 'error',
//             'message' => 'Impossible de récupérer la liste des élèves.',
//             'debug'   => $e->getMessage()
//         ], 500);
//     }
// }

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
            // ─── 1. Cherche la classe RÉELLE de l'élève pour l'année consultée,
            // via son dossier annuel (student_academic_records) en priorité.
            $yearRecord = \App\Models\Academic\StudentAcademicRecord::withoutGlobalScope('viewingYear')
                ->where('student_id', $student->id)
                ->where('academic_year_id', $viewingYearId)
                ->first();

            $yearClassId = $yearRecord?->class_id;

            // À défaut, tente via une vraie inscription de cette année précise.
            if (!$yearClassId) {
                $yearEnrollment = Enrollment::withoutGlobalScope('viewingYear')
                    ->where('student_id', $student->id)
                    ->where('academic_year_id', $viewingYearId)
                    ->first();
                $yearClassId = $yearEnrollment?->class_id;
            }

            if ($yearClassId) {
                // Classe trouvée pour CETTE année précise → affichage normal.
                $classe = Classe::withoutGlobalScope('viewingYear')->with('level.cycle')->find($yearClassId);
                $student->setRelation('classe', $classe);
                // ─── AJOUT : aligne le class_id BRUT sur la classe réelle de
                // l'année consultée — sinon le frontend (filtre/regroupement
                // par classe) continue de lire l'ancien pointeur stale.
                $student->class_id = $classe?->id;
                $student->last_enrollment = null;
            } elseif ($isActiveYearView) {
                // Cas légitime : sur l'année ACTIVE, pas encore réaffecté →
                // on indique d'où il vient, comme avant.
                $last = Enrollment::withoutGlobalScope('viewingYear')
                    ->where('student_id', $student->id)
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
                $student->class_id = null; // ← AJOUT : cohérent, pas encore réaffecté cette année
            } else {
                // Année ARCHIVÉE consultée : aucune classe trouvée pour cette
                // année précise = l'élève n'était vraiment pas là. "Non assigné",
                // jamais "Venait de..." dans ce contexte.
                $student->setRelation('classe', null);
                $student->class_id = null; // ← AJOUT : cohérent, non assigné cette année-là
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
        // 1. Validation de la donnée reçue (on attend un booléen ou 0/1)
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
    // public function store(\App\Http\Requests\StoreStudentRequest $request)
    // {
    //     // 1. Transaction SQL pour sécuriser la génération du matricule et l'écriture en BDD
    //     \Illuminate\Support\Facades\DB::beginTransaction();

    //     $photoPath = null;

    //     try {
    //         //  Génération automatique d'un matricule unique (Ex: GS-2026-0001)
    //         $currentYear = date('Y');
    //         $prefix = 'GS-' . $currentYear . '-';
            
    //         // Trouver le dernier matricule généré cette année-là
    //         $lastStudent = Student::withTrashed()
    //         ->where('matricule', 'LIKE', $prefix . '%')
    //             ->orderBy('matricule', 'desc')
    //             ->first();

    //         if ($lastStudent) {
    //             // On extrait le numéro séquentiel à 4 chiffres et on l'incrémente
    //             $lastSequence = (int) substr($lastStudent->matricule, -4);
    //             $newSequence = str_pad($lastSequence + 1, 4, '0', STR_PAD_LEFT);
    //         } else {
    //             $newSequence = '0001';
    //         }
            
    //         $matricule = $prefix . $newSequence;

    //         // 
    //         if ($request->hasFile('photo') && $request->file('photo')->isValid()) {
    //             // Sauvegarde automatique avec un nom unique dans storage/app/public/students_photos
    //             $photoPath = $request->file('photo')->store('students_photos', 'public');
    //         }

    //         //  Création de l'enregistrement de l'élève en Base de Données
    //         $student = \App\Models\Academic\Student::create([
    //             'matricule'        => $matricule,
    //             'last_name'        => strtoupper($request->last_name), // Nom standardisé en majuscules
    //             'first_name'       => ucwords(strtolower($request->first_name)), // Prénom bien formaté (Ex: Aminata)
    //             'gender'           => $request->gender,
    //             'birth_date'       => $request->birth_date,
    //             'birth_place'      => $request->birth_place,
    //             'phone'            => $request->phone,
    //             'email'            => $request->email,
    //             'address'          => $request->address,
    //             'class_id'         => $request->class_id,
    //             'academic_year_id' => $request->academic_year_id,
    //             'photo'            => $photoPath ? \Illuminate\Support\Facades\Storage::url($photoPath) : null, // URL publique accessible par le Front-end
    //             'is_active'        => filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN), // Force la conversion en booléen propre
    //             'created_by'       => \Illuminate\Support\Facades\Auth::id() ?? null, // Id de l'utilisateur connecté
    //         ]);

    //         \Illuminate\Support\Facades\DB::commit();

    //         // 5. Réponse JSON de succès interceptée par ton composant React
    //         return response()->json([
    //             'status'  => 'success',
    //             'message' => 'L\'élève ' . $student->first_name . ' ' . $student->last_name . ' a été créé avec succès avec le matricule ' . $student->matricule,
    //             'data'    => $student
    //         ], 201);

    //     } catch (\Exception $e) {
    //         \Illuminate\Support\Facades\DB::rollBack();

    //         // Sécurité : Si le fichier image a été écrit mais que la BDD a planté, on le supprime
    //         if ($photoPath) {
    //             \Illuminate\Support\Facades\Storage::disk('public')->delete($photoPath);
    //         }

    //         return response()->json([
    //             'status'  => 'error',
    //             'message' => 'Une erreur est survenue lors de l\'enregistrement de l\'élève en base de données.',
    //             'debug'   => $e->getMessage() // Tu pourras masquer le 'debug' en production
    //         ], 500);
    //     }
    // }

public function store(\App\Http\Requests\StoreStudentRequest $request)
{
    assert_writable_year();

    // ─── VERROU DE SÉCURITÉ PRÉVENTIF : COMPATIBILITÉ DE LA CAPACITÉ DE LA CLASSE ───
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

        // ── 1. Création du dossier élève (students) ──
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
            'class_id'              => $request->class_id,
            'academic_year_id'      => $request->academic_year_id,
            'photo'                 => $photoPath ? \Illuminate\Support\Facades\Storage::url($photoPath) : null,
            'is_active'             => filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN),
            'is_transferred'        => filter_var($request->is_transferred, FILTER_VALIDATE_BOOLEAN),
            'assignment_status'     => $request->assignment_status ?? 'non_affecte',
            'is_enrolled'           => false,
            'created_by'            => \Illuminate\Support\Facades\Auth::id() ?? null,
        ]);

        // ── 2. Tuteur (student_parents) ──
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

        // ── 3. Record de l'année (student_academic_records) ──
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
       /**
     * Récupérer les détails d'un élève spécifique
     * Route::get("/students/{id}", [StudentController::class, "show"]);
     */
    // public function show(string $id)
    // {
    //     try {
    //         // Chargement complet du dossier élève avec ses relations imbriquées
    //         $student = Student::with([
    //             'classe', 
    //             'academicYear', 
    //             'level.cycle',
    //             'parents',
    //             'documents',
    //             'latestEnrollment.financial'
    //         ])->findOrFail($id);

    //         // 1. Calculs financiers basés sur l'inscription de l'année en cours
    //         $latestEnrollment = $student->latestEnrollment;
    //         $financial = $latestEnrollment ? $latestEnrollment->financial : null;

    //         $totalDue = $financial ? (int) $financial->total_due : 0;
    //         $initialPayment = $financial ? (int) $financial->initial_payment : 0;

    //         // Note : Si vous créez plus tard une table 'payments' pour le module 6, 
    //         // vous ferez la somme ici : $otherPayments = $latestEnrollment->payments()->sum('amount');
    //         $otherPayments = 0; 

    //         $totalPaid = $initialPayment + $otherPayments;
    //         $remaining = max(0, $totalDue - $totalPaid);

    //         // 2. Construction de l'historique chronologique pour l'onglet "Paiements"
    //         $paymentsHistory = [];
    //         if ($financial && $initialPayment > 0) {
    //             $paymentsHistory[] = [
    //                 'id' => 'init_' . $latestEnrollment->id,
    //                 'label' => 'Acompte initial - Inscription ' . $latestEnrollment->enrollment_number,
    //                 'amount' => $initialPayment,
    //                 'date' => $latestEnrollment->enrollment_date ? $latestEnrollment->enrollment_date->format('Y-m-d') : null,
    //                 'status' => 'paid'
    //             ];
    //         }

    //         // 3. Injection des données calculées directement dans l'objet Student pour React
    //         $studentData = $student->toArray();
    //         $studentData['financial_summary'] = [
    //             'total_due'  => $totalDue,
    //             'total_paid' => $totalPaid,
    //             'remaining'  => $remaining,
    //         ];
    //         $studentData['payments'] = $paymentsHistory;

    //         return response()->json([
    //             'status'  => 'student',
    //             'student' => $studentData
    //         ], 200);

    //     } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
    //         return response()->json([
    //             'status'  => 'error',
    //             'message' => 'Élève introuvable.'
    //         ], 404);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status'  => 'error',
    //             'message' => 'Une erreur est survenue lors de la récupération des détails de l\'élève.',
    //             'debug'   => $e->getMessage()
    //         ], 500);
    //     }
    // }

//      public function show(Request $request, string $id)
// {
//     try {
//         // Student a le trait BelongsToEstablishment → déjà scopé par établissement.
//         $student = Student::with([
//             'classe',
//             'academicYear',
//             'parents',
//             // Record de l'année active (lv2, art, redoublement, décision)
//             'academicRecords' => function ($q) {
//                 $q->where('academic_year_id', current_active_year_id())
//                   ->with('classe');
//             },
//             // Dernière inscription + son volet financier
//             'latestEnrollment.financial',
//         ])->findOrFail($id);    

//         $currentRecord = $student->academicRecords->first();

//         // ── Calcul de la situation financière ──
//         $latestEnrollment = $student->latestEnrollment;
//         $financial = $latestEnrollment ? $latestEnrollment->financial : null;

//         $totalDue       = $financial ? (int) $financial->total_due : 0;
//         $initialPayment = $financial ? (int) $financial->initial_payment : 0;

//         // Si tu ajoutes plus tard une table 'payments', additionne-les ici :
//         // $otherPayments = $latestEnrollment ? (int) $latestEnrollment->payments()->sum('amount_paid') : 0;
//         $totalPaid = $initialPayment; // + $otherPayments;

//         $remaining = max(0, $totalDue - $totalPaid);

//         $financialSummary = [
//             'total_due'  => $totalDue,
//             'total_paid' => $totalPaid,
//             'remaining'  => $remaining,
//         ];

//         // ── Historique des paiements (pour l'onglet Paiements) ──
//         $paymentsHistory = [];
//         if ($financial && $initialPayment > 0 && $latestEnrollment) {
//             $paymentsHistory[] = [
//                 'id'     => 'init_' . $latestEnrollment->id,
//                 'label'  => 'Acompte initial - Inscription ' . $latestEnrollment->enrollment_number,
//                 'amount' => $initialPayment,
//                 'date'   => $latestEnrollment->enrollment_date
//                     ? $latestEnrollment->enrollment_date->format('Y-m-d')
//                     : null,
//                 'method' => $financial->payment_method,
//             ];
//         }

//         // On attache les données financières à l'objet élève
//         $studentData = $student->toArray();
//         $studentData['financial_summary'] = $financialSummary;
//         $studentData['payments'] = $paymentsHistory;

//         return response()->json([
//             "status"          => "success",
//             "donnees_eleve"   => $studentData,
//              "student"         => $studentData, 
//             "current_record"  => $currentRecord,
//         ]);

//     } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
//         return response()->json([
//             "status"  => "error",
//             "message" => "Élève introuvable ou n'appartenant pas à votre établissement."
//         ], 404);

//     } catch (\Exception $e) {
//         return response()->json([
//             "status"  => "error",
//             "message" => "Une erreur est survenue lors du chargement du dossier.",
//             "debug"   => $e->getMessage()
//         ], 500);
//     }
// }

    public function show(Request $request, string $id)
{
    try {
        $viewingYearId = current_viewing_year_id();
        $activeYearId  = current_active_year_id();
        $isActiveYearView = ((int) $viewingYearId === (int) $activeYearId);

        $student = Student::with(['parents'])->findOrFail($id);

        // ── Dossier annuel (lv2/art/redouble/décision) de l'année CONSULTÉE ──
        $currentRecord = \App\Models\Academic\StudentAcademicRecord::withoutGlobalScope('viewingYear')
            ->where('student_id', $student->id)
            ->where('academic_year_id', $viewingYearId)
            ->first();

        // ── Inscription de l'année CONSULTÉE (pas "la dernière") ──
        $yearEnrollment = Enrollment::withoutGlobalScope('viewingYear')
            ->where('student_id', $student->id)
            ->where('academic_year_id', $viewingYearId)
            ->first();

        // ── Classe réelle pour cette année précise ──
        $yearClassId = $currentRecord?->class_id ?? $yearEnrollment?->class_id;
        $classe = $yearClassId
            ? Classe::withoutGlobalScope('viewingYear')->with('level.cycle')->find($yearClassId)
            : null;

        $lastEnrollmentInfo = null;
        if (!$classe && $isActiveYearView) {
            $last = Enrollment::withoutGlobalScope('viewingYear')
                ->where('student_id', $student->id)
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

        // ── Situation financière DE L'ANNÉE CONSULTÉE UNIQUEMENT ──
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

        $paymentsHistory = [];
        if ($financial && $initialPayment > 0 && $yearEnrollment) {
            $paymentsHistory[] = [
                'id'     => 'init_' . $yearEnrollment->id,
                'label'  => 'Acompte initial - Inscription ' . $yearEnrollment->enrollment_number,
                'amount' => $initialPayment,
                'date'   => $yearEnrollment->enrollment_date ? $yearEnrollment->enrollment_date->format('Y-m-d') : null,
                'method' => $financial->payment_method,
            ];
        }

        $studentData = $student->toArray();
        $studentData['classe']            = $classe;
        $studentData['last_enrollment']   = $lastEnrollmentInfo;
        $studentData['financial_summary'] = $financialSummary;
        $studentData['payments']          = $paymentsHistory;

        // ─── AJOUT : année scolaire réellement consultée, pour que le
        // frontend affiche clairement "Dossier pour l'année X" sur la fiche,
        // au lieu de se fier à academic_year (qui pointe la DERNIÈRE année
        // connue de l'élève, pas forcément celle qu'on regarde).
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

            // Enregistrement de l'utilisateur qui fait l'action (Optionnel)
            $student->update([
                'updated_by' => Auth::id() ?? null
            ]);

            // 3. Déclenchement du Soft Delete (remplit la colonne deleted_at)
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
      /**
     * 1. Voir uniquement les élèves supprimés (La Corbeille)
     * Route::get("/students/trashed", [StudentController::class, "trashed"]);
     */
    public function trashed()
    {
        
        $students = Student::onlyTrashed()->get();

        return response()->json([
            'status' => 'success',
            'data'   => $students
        ], 200);
    }

        /**
     * Restaurer un élève spécifique à partir de son ID
     * Route::post("/students/{id}/restore", [StudentController::class, "restore"]);
     */
    public function restore(string $id)
    {
        assert_writable_year();
        try {
          
            $student = Student::onlyTrashed()->findOrFail($id);
            
            // Restaure le dossier (remet la colonne deleted_at à null)
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

    /**
     * Restaurer TOUS les élèves de la corbeille d'un coup
     * Route::post("/students/restore-all", [StudentController::class, "restoreAll"]);
     */
    public function restoreAll()
    {
        assert_writable_year();
        try {
            // Sélectionne tous les enregistrements supprimés et annule leur Soft Delete
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

    \Illuminate\Support\Facades\DB::beginTransaction();

    try {
        // Student scopé par le trait → 404 automatique si autre établissement
        $student = \App\Models\Academic\Student::findOrFail($id);

        $photoPath = $student->getRawOriginal('photo');

        // Remplacement photo
        if ($request->hasFile('photo') && $request->file('photo')->isValid()) {
            if ($student->photo) {
                $oldFile = str_replace('/storage/', '', $student->photo);
                if (\Illuminate\Support\Facades\Storage::disk('public')->exists($oldFile)) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($oldFile);
                }
            }
            $photoPath = $request->file('photo')->store('students_photos', 'public');
        }

        // ── 1. Champs directs de students ──
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
            'class_id'              => $request->class_id,
            'academic_year_id'      => $request->academic_year_id,
            'is_transferred'        => filter_var($request->is_transferred, FILTER_VALIDATE_BOOLEAN),
            'assignment_status'     => $request->assignment_status ?? 'non_affecte',
            'photo'                 => $request->hasFile('photo') ? \Illuminate\Support\Facades\Storage::url($photoPath) : $student->photo,
            'is_active'             => filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN),
            'updated_by'            => \Illuminate\Support\Facades\Auth::id() ?? null,
        ]);

        // ── 2. Record de l'ANNÉE ACTIVE (lv2, art, redouble) ──
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

        // ── 3. Tuteur (updateOrCreate sur le guardian) ──
        if (!empty($request->tutor_name)) {
            \App\Models\Academic\StudentParent::updateOrCreate(
                [
                    'student_id' => $student->id,
                    'type'       => 'guardian',
                ],
                [
                    'last_name'       => $request->tutor_name,
                    'first_name'      => '',
                    'phone'           => $request->tutor_phone ?: '0000000000',
                    'is_main_contact' => true,
                ]
            );
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


    


    










//     public function search(Request $request)
// {
//     $query = $request->query('q');
//     $enrolledOnly = $request->query('enrolled_only') === 'true';

//     if (strlen($query) < 2) {
//         return response()->json([], 200);
//     }

//     $establishmentId = current_establishment_id();
//     $activeYearId = current_active_year_id();

//     $nextYearId = \App\Models\Academic\AcademicYears::where('establishment_id', $establishmentId)
//         ->where('is_active', 0)
//         ->where('is_archived', 0)
//         ->where('start_date', '>', now())
//         ->orderBy('start_date', 'asc')
//         ->value('id') ?? $activeYearId;

//     $studentsQuery = Student::with([
//             'classe',
//             'academicRecords' => function ($q) use ($activeYearId) {
//                 $q->where('academic_year_id', $activeYearId);
//             },
//         ])
//         ->where('is_active', 1)
//         ->where(function ($q) use ($query) {
//             $q->where('first_name', 'LIKE', "%{$query}%")
//             ->orWhere('last_name', 'LIKE', "%{$query}%")
//             ->orWhere('matricule', 'LIKE', "%{$query}%");
//         });

//     if ($enrolledOnly) {
//         $studentsQuery->whereNotNull('class_id')
//             ->withExists(['enrollments' => function ($q) use ($nextYearId) {
//                 $q->where('academic_year_id', $nextYearId);
//             }]);
//     } else {
//         $studentsQuery->withExists(['enrollments' => function ($q) use ($activeYearId) {
//                 $q->where('academic_year_id', $activeYearId);
//             }])
//             ->withCount(['enrollments' => function ($q) {
//                 $q->withoutGlobalScope('viewingYear');
//             }]);
//     }

//     $students = $studentsQuery->take(20)->get(); // marge avant filtrage DFA="E"

//     // ─── Exclusion DFA="E" : basée sur le DERNIER DFA connu de l'élève ───
//     $students = $students->reject(function ($student) {
//         $lastDecision = \App\Models\Academic\StudentAcademicRecord::where('student_id', $student->id)
//             ->orderByDesc('academic_year_id')
//             ->value('year_end_decision');

//         return $lastDecision === 'E';
//     })->take(10)->values();

//     $formatted = $students->map(function ($student) use ($enrolledOnly) {
//         $lastEnrollment = \App\Models\officeAdministration\Enrollment::withoutGlobalScope('viewingYear')
//             ->where('student_id', $student->id)
//             ->with([
//                 'classe' => function ($q) {
//                     $q->withoutGlobalScope('viewingYear')->with('level.cycle');
//                 },
//                 'academicYear',
//             ])
//             ->orderByDesc('academic_year_id')
//             ->orderByDesc('id')
//             ->first();

//         $financial = null;
//         if ($lastEnrollment) {
//             $financial = \App\Models\officeAdministration\EnrollmentFinancial::withoutGlobalScopes()
//                 ->where('enrollment_id', $lastEnrollment->id)
//                 ->first();
//         }

//         $totalDue = $financial ? (int) $financial->total_due : 0;
//         $totalPaid = $financial ? (int) $financial->initial_payment : 0;
//         $soldeRestant = max(0, $totalDue - $totalPaid);
//         $hasDebt = $soldeRestant > 0;

//         $record = $student->academicRecords->first();
//         $yearEndDecision = $record?->year_end_decision;

//         $lastEnrollmentInfo = $lastEnrollment ? [
//             'classe' => $lastEnrollment->classe ? [
//                 'name'  => $lastEnrollment->classe->name,
//                 'level' => $lastEnrollment->classe->level?->name,
//                 'cycle' => $lastEnrollment->classe->level?->cycle?->name,
//             ] : null,
//             'academic_year' => $lastEnrollment->academicYear ? [
//                 'name' => $lastEnrollment->academicYear->name,
//             ] : null,
//         ] : null;

//         return [
//             'id'               => $student->id,
//             'matricule'        => $student->matricule,
//             'first_name'       => $student->first_name,
//             'last_name'        => $student->last_name,
//             'gender'           => $student->gender,
//             'class_id'         => $student->class_id,
//             'academic_year_id' => $student->academic_year_id,
//             'classe'           => $student->classe,
//             'last_enrollment'  => $lastEnrollmentInfo,

//             'enrollments_exists' => $student->enrollments_exists,
//             'enrollments_count'  => $student->enrollments_count ?? 0,

//             'current_class'                => $student->classe ? $student->classe->name : 'N/A',
//             'cycle'                        => ($student->classe && $student->classe->level) ? $student->classe->level->name : 'N/A',
//             'enrollments_exists_next_year' => $enrolledOnly ? $student->enrollments_exists : false,

//             'has_debt'     => $hasDebt,
//             'debt_amount'  => $soldeRestant,

//             'year_end_decision' => $yearEndDecision,
//             'current_level_id'  => $student->classe?->level_id,
//         ];
//     });

//     return response()->json($formatted->values(), 200);
// }

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
            ->withExists(['enrollments' => function ($q) use ($nextYearId) {
                $q->where('academic_year_id', $nextYearId);
            }]);
    } else {
        $studentsQuery->withExists(['enrollments' => function ($q) use ($activeYearId) {
                $q->where('academic_year_id', $activeYearId);
            }])
            ->withCount(['enrollments' => function ($q) {
                $q->withoutGlobalScope('viewingYear');
            }]);
    }

    $students = $studentsQuery->take(20)->get(); // marge avant filtrage DFA="E"

    // ─── Exclusion DFA="E" : basée sur le DERNIER DFA connu de l'élève,
    // scope 'viewingYear' de StudentAcademicRecord contourné explicitement.
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




//   




}