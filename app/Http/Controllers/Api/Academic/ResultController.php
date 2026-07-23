<?php

namespace App\Http\Controllers\Api\Academic;

use App\Http\Controllers\Controller;
use App\Models\Academic\ClassroomSubjectTeacher;
use App\Models\Academic\Grade;
use App\Models\Academic\Period;
use App\Models\Academic\PeriodAverage;
use App\Models\Academic\SubjectAverage;
use App\Models\officeAdministration\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ResultController extends Controller
{
    //


     /**
     * Calculer et enregistrer les moyennes d'une classe pour la période active
     * POST /api/academic/results/calculate
     */
    // public function calculateClassResults(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'classe_id' => 'required|exists:classes,id',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json(['errors' => $validator->errors()], 422);
    //     }

    //     $classeId = $request->classe_id;

    //     // 1. Trouver le trimestre ou semestre actif
    //     $activePeriod = Period::where('is_active', true)->first();
    //     if (!$activePeriod) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'Aucun trimestre ou semestre n\'est actif pour le calcul.'
    //         ], 422);
    //     }

    //     // 2. Récupérer les élèves inscrits dans cette classe
    //     $students = Enrollment::where('class_id', $classeId)->pluck('student_id')->toArray();
    //     if (empty($students)) {
    //         return response()->json(['status' => 'error', 'message' => 'Aucun élève trouvé dans cette classe.'], 422);
    //     }

    //     // 3. Récupérer les matières et coefficients configurés pour cette classe
    //     $attributions = ClassroomSubjectTeacher::where('classe_id', $classeId)->get();

    //     DB::beginTransaction();

    //     try {
    //         // --- PARTIE A : CALCUL DES MOYENNES PAR MATIÈRE ---
    //         foreach ($students as $studentId) {
    //             foreach ($attributions as $attr) {
    //                 // On récupère toutes les notes de l'élève pour cette matière et ce trimestre
    //                 $scores = Grade::where('student_id', $studentId)
    //                     ->whereHas('evaluation', function($query) use ($classeId, $attr, $activePeriod) {
    //                         $query->where('classe_id', $classeId)
    //                               ->where('subject_id', $attr->subject_id)
    //                               ->where('period_id', $activePeriod->id);
    //                     })
    //                     ->where('is_absent', false)
    //                     ->whereNotNull('score')
    //                     ->pluck('score');

    //                 if ($scores->count() > 0) {
    //                     // Moyenne simple de la matière (ex: (12 + 14) / 2 = 13)
    //                     $subjectAvg = $scores->average();

    //                     SubjectAverage::updateOrCreate(
    //                         [
    //                             'student_id' => $studentId,
    //                             'classe_id'  => $classeId,
    //                             'subject_id' => $attr->subject_id,
    //                             'period_id'  => $activePeriod->id,
    //                         ],
    //                         ['average' => round($subjectAvg, 2)]
    //                     );
    //                 }
    //             }
    //         }

    //         // --- PARTIE B : CALCUL DE LA MOYENNE GÉNÉRALE PONDÉRÉE AVEC LES COEFFS ---
    //         foreach ($students as $studentId) {
    //             $studentAverages = SubjectAverage::where('student_id', $studentId)
    //                 ->where('classe_id', $classeId)
    //                 ->where('period_id', $activePeriod->id)
    //                 ->get();

    //             $totalPoints = 0;
    //             $totalCoefficients = 0;

    //             foreach ($studentAverages as $subAvg) {
    //                 // On récupère le coefficient précis défini au Module 2
    //                 $coeff = $attributions->where('subject_id', $subAvg->subject_id)->first()->coefficient ?? 1;
                    
    //                 $totalPoints += ($subAvg->average * $coeff);
    //                 $totalCoefficients += $coeff;
    //             }

    //             if ($totalCoefficients > 0) {
    //                 $generalAverage = $totalPoints / $totalCoefficients;

    //                 PeriodAverage::updateOrCreate(
    //                     [
    //                         'student_id' => $studentId,
    //                         'classe_id'  => $classeId,
    //                         'period_id'  => $activePeriod->id,
    //                     ],
    //                     [
    //                         'general_average' => round($generalAverage, 2),
    //                         'is_validated'    => false
    //                     ]
    //                 );
    //             }
    //         }

    //         if ($totalCoefficients > 0) {
    //                 $generalAverage = $totalPoints / $totalCoefficients;

    //                 // 📝 Génération automatique de l'appréciation selon la moyenne
    //                 $appreciation = 'Insuffisant';
    //                 if ($generalAverage >= 16) { $appreciation = 'Excellent travail'; }
    //                 elseif ($generalAverage >= 14) { $appreciation = 'Très bien'; }
    //                 elseif ($generalAverage >= 12) { $appreciation = 'Bien'; }
    //                 elseif ($generalAverage >= 10) { $appreciation = 'Assez bien / Passable'; }
    //                 elseif ($generalAverage >= 8) { $appreciation = 'Doit redoubler d\'efforts'; }

    //                 PeriodAverage::updateOrCreate(
    //                     [
    //                         'student_id' => $studentId,
    //                         'classe_id'  => $classeId,
    //                         'period_id'  => $activePeriod->id,
    //                     ],
    //                     [
    //                         'general_average' => round($generalAverage, 2),
    //                         'appreciation'    => $appreciation, // 🟢 Ajout de l'appréciation ici
    //                         'is_validated'    => false
    //                     ]
    //                 );
    //             }
            

    //         // --- PARTIE C : LES RANGS GÉNÉRAUX DE LA CLASSE ---
    //         $periodAverages = PeriodAverage::where('classe_id', $classeId)
    //             ->where('period_id', $activePeriod->id)
    //             ->orderBy('general_average', 'desc')
    //             ->get();

    //         $rank = 1;
    //         foreach ($periodAverages as $pAvg) {
    //             $pAvg->update(['rank' => $rank]);
    //             $rank++;
    //         }

    //         DB::commit();

    //         return response()->json([
    //             'status' => 'success',
    //             'message' => 'Calcul des moyennes et classement terminés avec succès !'
    //         ], 200);

    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'Erreur lors du calcul.',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    /**
     * Calculer et enregistrer les moyennes d'une classe pour la période active
     * POST /api/academic/results/calculate
     */
      public function calculateClassResults(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'classe_id' => 'required|exists:classes,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $classeId = $request->classe_id;

        // 1. Trouver le trimestre ou semestre actif
        $activePeriod = Period::where('is_active', true)->first();
        if (!$activePeriod) {
            return response()->json([
                'status' => 'error',
                'message' => 'Aucun trimestre ou semestre n\'est actif pour le calcul.'
            ], 422);
        }

        // 2. Récupérer les élèves inscrits dans cette classe
        $students = Enrollment::where('class_id', $classeId)->pluck('student_id')->toArray();
        if (empty($students)) {
            return response()->json(['status' => 'error', 'message' => 'Aucun élève trouvé dans cette classe.'], 422);
        }

        // 3. Récupérer les matières et coefficients configurés pour cette classe
        $attributions = ClassroomSubjectTeacher::where('classe_id', $classeId)->get();

        DB::beginTransaction();

        try {
            // --- PARTIE A : CALCUL DES MOYENNES PAR MATIÈRE ---
            foreach ($students as $studentId) {
                foreach ($attributions as $attr) {
                    // On récupère toutes les notes de l'élève pour cette matière et ce trimestre
                    $scores = Grade::where('student_id', $studentId)
                        ->whereHas('evaluation', function($query) use ($classeId, $attr, $activePeriod) {
                            $query->where('classe_id', $classeId)
                                  ->where('subject_id', $attr->subject_id)
                                  ->where('period_id', $activePeriod->id);
                        })
                        ->where('is_absent', false)
                        ->whereNotNull('score')
                        ->pluck('score');

                    if ($scores->count() > 0) {
                        $subjectAvg = $scores->average();

                        // 🟢 CORRECTION ULTRA-SÉCURISÉE : Recherche stricte des 3 clés uniques
                        SubjectAverage::updateOrCreate(
                            [
                                'student_id' => (int)$studentId,
                                'subject_id' => (int)$attr->subject_id,
                                'period_id'  => (int)$activePeriod->id,
                            ],
                            [
                                'classe_id'  => (int)$classeId,
                                'average'    => round($subjectAvg, 2)
                            ]
                        );
                    }
                }
            }

            // --- PARTIE B : CALCUL DE LA MOYENNE GÉNÉRALE PONDÉRÉE ---
            foreach ($students as $studentId) {
                $studentAverages = SubjectAverage::where('student_id', $studentId)
                    ->where('classe_id', $classeId)
                    ->where('period_id', $activePeriod->id)
                    ->get();

                $totalPoints = 0;
                $totalCoefficients = 0;

                foreach ($studentAverages as $subAvg) {
                    $coeff = $attributions->where('subject_id', $subAvg->subject_id)->first()->coefficient ?? 1;
                    $totalPoints += ($subAvg->average * $coeff);
                    $totalCoefficients += $coeff;
                }

                if ($totalCoefficients > 0) {
                    $generalAverage = $totalPoints / $totalCoefficients;

                    // 🟢 CORRECTION : Écrase l'ancien bilan s'il existe déjà
                    PeriodAverage::updateOrCreate(
                        [
                            'student_id' => (int)$studentId,
                            'period_id'  => (int)$activePeriod->id,
                        ],
                        [
                            'classe_id'       => (int)$classeId,
                            'general_average' => round($generalAverage, 2),
                            'is_validated'    => false
                        ]
                    );
                }
            }

            // --- PARTIE C : LES RANGS GÉNÉRAUX DE LA CLASSE ---
            $periodAverages = PeriodAverage::where('classe_id', $classeId)
                ->where('period_id', $activePeriod->id)
                ->orderBy('general_average', 'desc')
                ->get();

            $rank = 1;
            foreach ($periodAverages as $pAvg) {
                $pAvg->update(['rank' => $rank]);
                $rank++;
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Calcul des moyennes et classement terminés avec succès !'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors du calcul.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

        /**
     * Récupérer les moyennes générales calculées d'une classe pour l'affichage React
     * GET /api/academic/results/classes/{classeId}
     */
    public function getClassResults(string $classeId)
    {
        // 1. On trouve la période active
        $activePeriod = Period::where('is_active', true)->first();
        if (!$activePeriod) {
            return response()->json(['status' => 'error', 'message' => 'Aucune période active.'], 422);
        }

        // 2. On récupère les moyennes générales triées (Classement) avec la bonne syntaxe de jointure
        // 🟢 CORRECTION ICI : 'period_averages.student_id' (avec un point, pas de parenthèses !)
        $results = PeriodAverage::where('period_averages.classe_id', $classeId)
            ->where('period_averages.period_id', $activePeriod->id)
            ->join('students', 'period_averages.student_id', '=', 'students.id')
            ->select('period_averages.*', 'students.first_name', 'students.last_name', 'students.matricule')
            ->orderBy('period_averages.general_average', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'period_name' => $activePeriod->name,
            'data' => $results
        ], 200);
    }


    /**
     * Valider définitivement les résultats d'une classe pour la période
     * POST /api/academic/results/validate
     */
    public function validateClassResults(Request $request)
    {
        $request->validate([
            'classe_id' => 'required|exists:classes,id',
        ]);

        $activePeriod = Period::where('is_active', true)->first();
        
        if (!$activePeriod) {
            return response()->json(['status' => 'error', 'message' => 'Aucune période active.'], 422);
        }

        // On passe toutes les moyennes de cette classe pour ce trimestre à "Validé"
        PeriodAverage::where('classe_id', $request->classe_id)
            ->where('period_id', $activePeriod->id)
            ->update(['is_validated' => true]);

        return response()->json([
            'status' => 'success',
            'message' => 'Les résultats de la classe ont été validés officiellement !'
        ], 200);
    }


    /**
     * Récupérer le détail des moyennes par matière d'un élève pour le trimestre actif
     * GET /api/academic/results/student/{studentId}/details?classe_id=1
     */
    public function getStudentSubjectDetails(Request $request, string $studentId)
    {
        $request->validate([
            'classe_id' => 'required|exists:classes,id',
        ]);

        // 1. Trouver la période active
        $activePeriod = Period::where('is_active', true)->first();
        if (!$activePeriod) {
            return response()->json(['status' => 'error', 'message' => 'Aucune période active.'], 422);
        }

        // 2. Aller chercher les moyennes par matière de cet élève
        // On fait une jointure avec la table subjects pour récupérer les vrais noms des matières
        $details = SubjectAverage::where('student_id', $studentId)
            ->where('classe_id', $request->classe_id)
            ->where('period_id', $activePeriod->id)
            ->join('subjects', 'subject_averages.subject_id', '=', 'subjects.id')
            ->select('subject_averages.*', 'subjects.name as subject_name', 'subjects.code as subject_code')
            ->orderBy('subjects.name', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'student_id' => $studentId,
            'period_name' => $activePeriod->name,
            'data' => $details
        ], 200);
    }

}
