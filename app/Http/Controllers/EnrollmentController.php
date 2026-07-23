<?php

namespace App\Http\Controllers;

use App\Models\Academic\Classe;
use App\Models\Academic\Student;
use App\Models\officeAdministration\Enrollment;
use App\Models\officeAdministration\EnrollmentFinancial;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EnrollmentController extends Controller
{
    //

//    public function store(Request $request)
// {
//     // 1. Validation stricte (Montants obligatoirement entiers positifs pour le FCFA) 
//     $validated = $request->validate([
//         'student_id'       => ['required', 'exists:students,id'],
//         'class_id'         => ['required', 'exists:classes,id'],
//         'academic_year_id' => ['required', 'exists:academic_years,id'],
//         'type'             => ['required', 'in:new,re_enrollment'],
//         'enrollment_date'  => ['required', 'date'],
//         'notes'            => ['nullable', 'string'],
        
//         // Validation FCFA (entiers)
//         'registration_fee' => ['required', 'integer', 'min:0'],
//         'tuition_fee'      => ['required', 'integer', 'min:0'],
//         'annex_fee'        => ['required', 'integer', 'min:0'],
//         'discount_amount'  => ['required', 'integer', 'min:0'],
//         'initial_payment'  => ['required', 'integer', 'min:0'],
//         'payment_method'   => ['required', 'in:cash,online,none'],
        
//     ]);

//     // 2. VERROU FINANCIER DE SÉCURITÉ : Bloquer la réinscription si scolarité non soldée
//     $currentStudent = Student::with('latestEnrollment.financial')->findOrFail($request->student_id);

//     if ($request->type === 're_enrollment' && $currentStudent->latestEnrollment) {
//         $currentFinancial = $currentStudent->latestEnrollment->financial;
//         if ($currentFinancial) {
//             $resteA_Payer = (int) $currentFinancial->total_due - (int) $currentFinancial->initial_payment;
//             if ($resteA_Payer > 0) {
//                 return response()->json([
//                     'status'  => 'error',
//                     'message' => "Interdit : Cet élève traîne une dette de " . number_format($resteA_Payer, 0, '', ' ') . " FCFA sur l'année en cours. Veuillez régulariser sa situation à la caisse."
//                 ], 422);
//             }
//         }
//     }

//     // 3. Sécurité : Empêcher le doublon d'inscription pour la même année scolaire destination
//     $exists = Enrollment::where('student_id', $request->student_id)
//         ->where('academic_year_id', $request->academic_year_id)
//         ->exists();

//     if ($exists) {
//         return response()->json([
//             'status'  => 'error',
//             'message' => "Cet élève dispose déjà d'un dossier d'inscription actif pour cette année académique."
//         ], 422);
//     }

//     $userId = Auth::id(); 

//     // 4. Traitement sécurisé dans une transaction de base de données
//     return DB::transaction(function () use ($validated, $userId) {
        
//         // Génération dynamique du numéro unique : INS-ANNEE-COMPTEUR
//         $currentYear = date('Y');
//         $lastEnrollment = Enrollment::whereRaw("enrollment_number LIKE 'INS-{$currentYear}-%'")
//             ->latest('id')
//             ->first();

//         if ($lastEnrollment) {
//             // Extrait les 5 derniers caractères numériques et ajoute 1
//             $lastNumber = (int) substr($lastEnrollment->enrollment_number, -5);
//             $nextNumber = $lastNumber + 1;
//         } else {
//             $nextNumber = 1;
//         }

//         $enrollmentNumber = 'INS-' . $currentYear . '-' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);

//         // Création de la fiche d'inscription principale
//         $enrollment = Enrollment::create([
//             'student_id'        => $validated['student_id'],
//             'class_id'          => $validated['class_id'],
//             'academic_year_id'  => $validated['academic_year_id'],
//             'enrollment_number' => $enrollmentNumber,
//             'type'              => $validated['type'],
//             'enrollment_date'   => $validated['enrollment_date'],
//             'notes'             => $validated['notes'] ?? null,
//             'created_by' => $userId, 
           
//         ]);

//         // Calcul du net à payer en FCFA
//         $subtotal = $validated['registration_fee'] + $validated['tuition_fee'] + $validated['annex_fee'];
//         $totalDue = $subtotal - $validated['discount_amount'];
//         if ($totalDue < 0) $totalDue = 0;

//         // Création du volet financier lié
//         EnrollmentFinancial::create([
//             'enrollment_id'    => $enrollment->id,
//             'registration_fee' => $validated['registration_fee'],
//             'tuition_fee'      => $validated['tuition_fee'],
//             'annex_fee'        => $validated['annex_fee'],
//             'discount_amount'  => $validated['discount_amount'],
//             'total_due'        => $totalDue,
//             'initial_payment'  => $validated['initial_payment'],
//             'payment_method'   => $validated['payment_method'],
//         ]);

//         // Synchronisation de la classe actuelle sur le dossier permanent de l'élève
//         $student = Student::find($validated['student_id']);
//         $student->update([
//             'class_id'         => $validated['class_id'],
//             'academic_year_id' => $validated['academic_year_id'],
//         ]);

//         return response()->json([
//             'status'            => 'success',
//             'message'           => "Inscription validée avec succès sous le numéro {$enrollmentNumber}.",
//             'enrollment_number' => $enrollmentNumber
//         ], 201);
//     });
// }






//     public function store(Request $request)
// {
//     assert_writable_year();

//     // 1. Validation stricte (Montants obligatoirement entiers positifs pour le FCFA)
//     $validated = $request->validate([
//         'student_id'       => ['required', 'exists:students,id'],
//         'class_id'         => ['required', 'exists:classes,id'],
//         'academic_year_id' => ['required', 'exists:academic_years,id'],
//         'type'             => ['required', 'in:new,re_enrollment'],
//         'enrollment_date'  => ['required', 'date'],
//         'notes'            => ['nullable', 'string'],

//         'registration_fee' => ['required', 'integer', 'min:0'],
//         'tuition_fee'      => ['required', 'integer', 'min:0'],
//         'annex_fee'        => ['required', 'integer', 'min:0'],
//         'discount_amount'  => ['required', 'integer', 'min:0'],
//         'initial_payment'  => ['required', 'integer', 'min:0'],
//         'payment_method'   => ['required', 'in:cash,online,none'],
//     ]);

//     // Vérifier que l'année ET la classe appartiennent bien à l'établissement du user
//     $yearOwned = \App\Models\Academic\AcademicYears::where('id', $validated['academic_year_id'])
//         ->where('establishment_id', current_establishment_id())
//         ->exists();

//     if (!$yearOwned) {
//         return response()->json([
//             'status'  => 'error',
//             'message' => "Année scolaire invalide pour votre établissement."
//         ], 422);
//     }

//     // ─── VERROU : la classe doit appartenir à l'année scolaire soumise ───
//     $classe = Classe::findOrFail($validated['class_id']);
//     if ($classe->academic_year_id != $validated['academic_year_id']) {
//         return response()->json([
//             'status'  => 'error',
//             'message' => "Cette classe n'appartient pas à l'année scolaire sélectionnée."
//         ], 422);
//     }

//     $currentStudent = Student::with('classe')->findOrFail($validated['student_id']);

//     // ─── SÉCURITÉ : on prend le DERNIER DFA connu de l'élève, peu importe
//     // l'année active du moment. Un "E" enregistré sur 2025-2026 continue de
//     // bloquer même après activation de 2026-2027, tant qu'aucun nouveau DFA
//     // (différent) n'a été enregistré depuis.
//     $lastDecision = \App\Models\Academic\StudentAcademicRecord::where('student_id', $currentStudent->id)
//         ->orderByDesc('academic_year_id')
//         ->value('year_end_decision');

//     if ($lastDecision === 'E') {
//         return response()->json([
//             'status'  => 'error',
//             'message' => "Cet élève a été exclu et ne peut plus être inscrit ni réinscrit."
//         ], 422);
//     }

//     // ─── Reliquat : requête DIRECTE sur Enrollment ET sur EnrollmentFinancial,
//     // sans passer par les relations Eloquent, dont les scopes internes réappliquent
//     // silencieusement le filtre d'année consultée.
//     $previousBalance = 0;

//     if ($validated['type'] === 're_enrollment') {
//         $lastEnrollment = Enrollment::withoutGlobalScope('viewingYear')
//             ->where('student_id', $currentStudent->id)
//             ->orderByDesc('academic_year_id')
//             ->orderByDesc('id')
//             ->first();

//         if ($lastEnrollment) {
//             $financial = \App\Models\officeAdministration\EnrollmentFinancial::withoutGlobalScopes()
//                 ->where('enrollment_id', $lastEnrollment->id)
//                 ->first();

//             if ($financial) {
//                 $previousBalance = max(0, (int) $financial->total_due - (int) $financial->initial_payment);
//             }
//         }

//         // ─── VERROU : un redoublant (dernier DFA = "R") ne peut être réinscrit
//         // que dans une classe du MÊME niveau que sa classe actuelle.
//         if ($lastDecision === 'R') {
//             $currentLevelId = $currentStudent->classe?->level_id;
//             $targetLevelId  = $classe->level_id ?? null;

//             if (!$currentLevelId || $targetLevelId != $currentLevelId) {
//                 return response()->json([
//                     'status'  => 'error',
//                     'message' => "Cet élève redouble : il ne peut être réinscrit que dans une classe du même niveau que sa classe actuelle."
//                 ], 422);
//             }
//         }
//     }

//     // 3. Sécurité : Empêcher le doublon d'inscription pour la même année scolaire destination
//     $exists = Enrollment::where('student_id', $validated['student_id'])
//         ->where('academic_year_id', $validated['academic_year_id'])
//         ->exists();

//     if ($exists) {
//         return response()->json([
//             'status'  => 'error',
//             'message' => "Cet élève dispose déjà d'un dossier d'inscription actif pour cette année académique."
//         ], 422);
//     }

//     // ─── VERROU DE SÉCURITÉ : CAPACITÉ MAXIMALE DE LA CLASSE ───
//     $maxCapacity = (int) $classe->capacity;

//     $currentCount = Student::where('class_id', $validated['class_id'])
//         ->where('academic_year_id', $validated['academic_year_id'])
//         ->where('id', '!=', $validated['student_id'])
//         ->count();

//     if ($currentCount >= $maxCapacity) {
//         return response()->json([
//             'status'  => 'error',
//             'message' => "Surcharge refusée : La classe '{$classe->name}' a atteint sa capacité limite autorisée de {$maxCapacity} places pour cette année académique."
//         ], 422);
//     }

//     $userId = Auth::id();

//     // 4. Traitement sécurisé dans une transaction de base de données
//     return DB::transaction(function () use ($validated, $userId, $previousBalance) {

//         $estabPrefix = current_establishment_prefix();
//         $yearShort   = current_school_year_short();
//         $insPrefix   = "INS-{$estabPrefix}-{$yearShort}-";

//         $lastEnrollment = Enrollment::where('enrollment_number', 'LIKE', $insPrefix . '%')
//             ->latest('id')
//             ->first();

//         if ($lastEnrollment) {
//             $lastNumber = (int) substr($lastEnrollment->enrollment_number, -5);
//             $nextNumber = $lastNumber + 1;
//         } else {
//             $nextNumber = 1;
//         }

//         $enrollmentNumber = $insPrefix . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);

//         $enrollment = Enrollment::create([
//             'student_id'        => $validated['student_id'],
//             'class_id'          => $validated['class_id'],
//             'academic_year_id'  => $validated['academic_year_id'],
//             'enrollment_number' => $enrollmentNumber,
//             'type'              => $validated['type'],
//             'enrollment_date'   => $validated['enrollment_date'],
//             'notes'             => $validated['notes'] ?? null,
//             'created_by'        => $userId,
//         ]);

//         $subtotal = $validated['registration_fee'] + $validated['tuition_fee'] + $validated['annex_fee'];
//         $totalDue = $subtotal + $previousBalance - $validated['discount_amount'];
//         if ($totalDue < 0) $totalDue = 0;

//         EnrollmentFinancial::create([
//             'enrollment_id'    => $enrollment->id,
//             'registration_fee' => $validated['registration_fee'],
//             'tuition_fee'      => $validated['tuition_fee'],
//             'annex_fee'        => $validated['annex_fee'],
//             'discount_amount'  => $validated['discount_amount'],
//             'previous_balance' => $previousBalance,
//             'total_due'        => $totalDue,
//             'initial_payment'  => $validated['initial_payment'],
//             'payment_method'   => $validated['payment_method'],
//         ]);

//         $student = Student::find($validated['student_id']);
//         $student->update([
//             'class_id'         => $validated['class_id'],
//             'academic_year_id' => $validated['academic_year_id'],
//         ]);

//         return response()->json([
//             'status'            => 'success',
//             'message'           => "Inscription validée avec succès sous le numéro {$enrollmentNumber}.",
//             'enrollment_number' => $enrollmentNumber
//         ], 201);
//     });
// }

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
        'discount_amount'  => ['required', 'integer', 'min:0'],
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

    // ─── SÉCURITÉ : dernier DFA connu de l'élève, scope 'viewingYear' de
    // StudentAcademicRecord contourné explicitement pour ne pas le perdre
    // de vue après un changement d'année active.
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

    $exists = Enrollment::where('student_id', $validated['student_id'])
        ->where('academic_year_id', $validated['academic_year_id'])
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

        $subtotal = $validated['registration_fee'] + $validated['tuition_fee'] + $validated['annex_fee'];
        $totalDue = $subtotal + $previousBalance - $validated['discount_amount'];
        if ($totalDue < 0) $totalDue = 0;

        EnrollmentFinancial::create([
            'enrollment_id'    => $enrollment->id,
            'registration_fee' => $validated['registration_fee'],
            'tuition_fee'      => $validated['tuition_fee'],
            'annex_fee'        => $validated['annex_fee'],
            'discount_amount'  => $validated['discount_amount'],
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
            'enrollment_number' => $enrollmentNumber
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

        // Reformatage léger des clés pour s'imbriquer sans toucher à votre structure JSX
        $formatted = $enrollments->map(function ($enr) {
            return [
                'id'                => $enr->id,
                'enrollment_number' => $enr->enrollment_number,
                'type'              => $enr->type,
                'status'            => $enr->status,
                'enrollment_date'   => $enr->enrollment_date ? $enr->enrollment_date->format('Y-m-d') : null,
                'notes'             => $enr->notes,
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

    /**
     * Valide administrativement l'inscription.
     */
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

        // ✅ On récupère l'élève via la clé student_id de l'inscription (pas via $id)
        $student = Student::findOrFail($enrollment->student_id);
        $student->update([
            'is_enrolled' => 1, // l'inscription vient d'être validée
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

    /**
     * Annule une inscription.
     */
    public function cancelEnrollment(string $id)
    {
        assert_writable_year();
        $enrollment = Enrollment::findOrFail($id);
        $enrollment->update(['status' => 'cancelled']);

        return response()->json(['status' => 'success', 'message' => 'Inscription annulée.']);
    }

        public function show(string $id)
    {
        try {
            // Chargement imbriqué de toutes les relations du modèle Enrollment
            $enrollment = Enrollment::with([
                'student', 
                'classe.level', 
                'academicYear', 
                'financial',
                'validator'
            ])->findOrFail($id);
             // Récupération manuelle du créateur si la relation n'est pas encore déclarée dans le modèle
        $creatorUser = User::find($enrollment->created_by);
        $validatorUser = $enrollment->validator ?? User::find($enrollment->validated_by);

            // Transformation des données pour coller à la structure d'objet de l'interface React , faut pas que j'oublie un point cle !
            $formatted = [
                'id'                => $enrollment->id,
                'enrollment_number' => $enrollment->enrollment_number,
                'type'              => $enrollment->type,
                'status'            => $enrollment->status,
                'enrollment_date'   => $enrollment->enrollment_date ? $enrollment->enrollment_date->format('Y-m-d') : null,
                'notes'             => $enrollment->notes,
                'created_at'        => Carbon::parse($enrollment->created_at)->toISOString(),
                'validated_at'      => $enrollment->validated_at ? Carbon::parse($enrollment->validated_at)->toISOString() : null,
                   // --- AJOUT DE LA TRAÇABILITÉ DES AGENTS ---
                'created_by' => $creatorUser ? [
                    'id'   => $creatorUser->id,
                    'name' => $creatorUser->name, // Retournera 'Stephane'
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
                    'photo'       => $enrollment->student->photo, // ← AJOUT : manquait, d'où la photo absente sur la fiche
                    'birth_date'  => $enrollment->student->birth_date ? $enrollment->student->birth_date->format('Y-m-d') : null,
                    'birth_place' => $enrollment->student->birth_place,
                    'phone'       => $enrollment->student->phone,
                    'email'       => $enrollment->student->email,
                    'address'     => $enrollment->student->address,
                ] : null,
                
                'classe' => $enrollment->classe ? [
                    'name'  => $enrollment->classe->name,
                    'level' => $enrollment->classe->level ? $enrollment->classe->level->name : 'N/A',
                    'cycle' => ($enrollment->classe && $enrollment->classe->level) ? $enrollment->classe->level->name : 'N/A',
                ] : null,
                
                'academic_year' => [
                    'name' => $enrollment->academicYear ? $enrollment->academicYear->name : 'N/A'
                ],
                
                // Les données financières en FCFA
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


    /**
 * Calcule le reliquat (dette non soldée) de la dernière inscription d'un élève.
 * Appelé par le frontend dès qu'un élève est sélectionné en réinscription.
 */
  

    //     public function getStudentBalance(string $studentId)
    // {
    //     $student = Student::find($studentId);

    //     if (!$student) {
    //         return response()->json([
    //             'status'  => 'error',
    //             'message' => "Élève introuvable."
    //         ], 404);
    //     }

    //     $lastEnrollment = \App\Models\officeAdministration\Enrollment::withoutGlobalScope('viewingYear')
    //         ->where('student_id', $student->id)
    //         ->with(['financial', 'academicYear'])
    //         ->orderByDesc('academic_year_id')
    //         ->orderByDesc('id')
    //         ->first();

    //     $financial = $lastEnrollment ? $lastEnrollment->financial : null;

    //     $previousBalance = 0;
    //     if ($financial) {
    //         $previousBalance = max(0, (int) $financial->total_due - (int) $financial->initial_payment);
    //     }

    //     return response()->json([
    //         'status'            => 'success',
    //         'previous_balance'  => $previousBalance,
    //         'previous_year'     => $lastEnrollment?->academicYear?->name,
    //     ]);
    // }

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
}