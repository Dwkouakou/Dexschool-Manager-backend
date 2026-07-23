<?php

namespace App\Http\Controllers\Api\Academic;

use App\Http\Controllers\Controller;
use App\Models\Academic\AcademicYears;
use App\Models\Academic\Evaluation;
use App\Models\Academic\Grade;
use App\Models\Academic\Period;
use App\Models\officeAdministration\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EvaluationController extends Controller
{
    /**
     * 1. LISTER LES ÉVALUATIONS
     * GET /api/academic/evaluations
     */
    public function index(Request $request)
    {
        $query = Evaluation::with(['classe.level', 'subject', 'period']);

        // Filtrer par trimestre/semestre précis si demandé
        if ($request->has('period_id')) {
            $query->where('period_id', $request->period_id);
        } else {
            // Sinon, filtre automatique sur la période active
            $activePeriod = Period::where('is_active', true)->first();
            if ($activePeriod) {
                $query->where('period_id', $activePeriod->id);
            }
        }

        if ($request->has('classe_id')) {
            $query->where('classe_id', $request->classe_id);
        }

        if ($request->has('subject_id')) {
            $query->where('subject_id', $request->subject_id);
        }

        $evaluations = $query->latest()->get();

        return response()->json([
            'status' => 'success',
            'data' => $evaluations
        ], 200);
    }

    /**
     * 2. CRÉER UNE NOUVELLE ÉVALUATION
     * POST /api/academic/evaluations
     */
    public function storeEvaluation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title'       => 'required|string|max:255',
            'type'        => 'required|in:interrogation,devoir,examen',
            'date'        => 'required|date',
            'classe_id'   => 'required|exists:classes,id',
            'subject_id'  => 'required|exists:subjects,id',
            'employee_id' => 'required|exists:employees,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $activePeriod = Period::where('is_active', true)->first();
        if (!$activePeriod) {
            return response()->json([
                'status' => 'error',
                'message' => "Aucun découpage (trimestre/semestre) actif n'est configuré."
            ], 422);
        }

        try {
            // La transaction commence ici (Ligne 81 environ)
            $evaluation = DB::transaction(function () use ($request, $activePeriod) {
                
                $eval = Evaluation::create([
                    'title'       => $request->title,
                    'type'        => $request->type,
                    'date'        => $request->date,
                    'classe_id'   => $request->classe_id,
                    'subject_id'  => $request->subject_id,
                    'employee_id' => $request->employee_id,
                    'period_id'   => $activePeriod->id,
                    'max_score'   => 20,
                ]);

                $enrolledStudents = Enrollment::where('class_id', $request->classe_id)->pluck('student_id');

                foreach ($enrolledStudents as $studentId) {
                    Grade::create([
                        'evaluation_id' => $eval->id,
                        'student_id'    => $studentId,
                        'score'         => null,
                        'is_absent'     => false,
                    ]);
                }

                return $eval;
            }); // 🌟 CORRECTION ICI : Fermeture propre de la fonction anonyme et de la transaction

            return response()->json([
                'status' => 'success',
                'message' => 'Évaluation créée et grille de notes initialisée.',
                'data' => $evaluation->load(['classe', 'subject'])
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error', 
                'message' => 'Erreur : ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 3. CHARGER LA STRUCTURE DE SAISIE DES NOTES
     * GET /api/academic/evaluations/{id}/grades
     */
    public function getGradesStructure(string $id)
    {
        $evaluation = Evaluation::with(['classe', 'subject'])->find($id);

        if (!$evaluation) {
            return response()->json(['status' => 'error', 'message' => 'Évaluation introuvable.'], 404);
        }

        $grades = Grade::where('evaluation_id', $id)
            ->join('students', 'grades.student_id', '=', 'students.id')
            ->select('grades.*', 'students.first_name', 'students.last_name', 'students.matricule')
            ->orderBy('students.last_name', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'evaluation' => $evaluation,
            'students' => $grades
        ], 200);
    }

    /**
     * 4. ENREGISTRER / SOUMETTRE LES NOTES EN MASSE
     * POST /api/academic/evaluations/{id}/grades
     */
   /**
 * Soumettre les notes d'une évaluation
 * POST /api/evaluations/{id}/grades
 */
public function submitGrades(Request $request, string $id)
{
    $evaluation = Evaluation::find($id);

    if (!$evaluation) {
        return response()->json([
            'status'  => 'error',
            'message' => 'Évaluation introuvable.'
        ], 404);
    }

    $validator = Validator::make($request->all(), [
        'grades'                     => 'required|array',
        'grades.*.student_id'        => 'required|exists:students,id',
        'grades.*.score'             => 'nullable|numeric|min:0|max:' . $evaluation->max_score,
        'grades.*.is_absent'         => 'boolean',
        'grades.*.teacher_comment'   => 'nullable|string|max:255',  // ← NE PAS OUBLIER
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    try {
        DB::transaction(function () use ($request, $id) {
            foreach ($request->grades as $gradeData) {
                Grade::updateOrCreate(
                    [
                        'evaluation_id' => $id,
                        'student_id'    => $gradeData['student_id'],
                    ],
                    [
                        'score'           => $gradeData['is_absent'] ? null : ($gradeData['score'] ?? null),
                        'is_absent'       => $gradeData['is_absent'] ?? false,
                        'teacher_comment' => $gradeData['teacher_comment'] ?? null,  // ← LA LIGNE MANQUANTE
                    ]
                );
            }
        });

        return response()->json([
            'status'  => 'success',
            'message' => 'Notes enregistrées avec succès !'
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'status'  => 'error',
            'message' => 'Erreur lors de l\'enregistrement des notes.'
        ], 500);
    }
}

    /**
     * Détails d'une évaluation avec ses relations
     * GET /api/evaluations/{id}
     */
    public function show(string $id)
    {
        $evaluation = Evaluation::with(['classe', 'subject', 'teacher'])->find($id);

        if (!$evaluation) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Évaluation introuvable.'
            ], 404);
        }

        return response()->json($evaluation, 200);
    }
}