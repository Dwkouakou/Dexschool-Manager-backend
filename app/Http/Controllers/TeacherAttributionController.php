<?php

namespace App\Http\Controllers;

use App\Models\Academic\Classe;
use App\Models\Academic\ClassroomSubjectTeacher;
use App\Models\Academic\Subject;
use App\Models\Personel\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TeacherAttributionController extends Controller
{
    //
     /**
     * 1. FONCTION CORRIGÉE : Récupérer uniquement les enseignants
     * GET /api/academic/teachers
     */
    public function getTeachers()
    {
        // On récupère les employés actifs dont le poste a pour slug 'enseignant'
        $teachers = Employee::where('status', 'active') // ou selon ton champ d'activité de l'employé
                            ->whereHas('position', function($query) {
                                $query->where('slug', 'enseignant');
                            })
                            ->orderBy('last_name', 'asc')
                            ->orderBy('first_name', 'asc')
                            ->get();

        return response()->json($teachers, 200);
    }


    /**
     * 4. Récupérer toutes les classes (Demandé par React)
     * GET /api/classes
     */
    public function getClasses()
    {
        // Ajuste le nom de ton modèle (Classe ou Classroom) et le tri
        $classes = Classe::orderBy('name', 'asc')->get(); 
        return response()->json($classes, 200);
    }


    /**
     * 5. Récupérer toutes les matières (Demandé par React)
     * GET /api/academic/subjects
     */
    public function getSubjects()
    {
        $subjects = Subject::orderBy('name', 'asc')->get();
        return response()->json($subjects, 200);
    }
    
    /**
     *  Récupérer un enseignant spécifique (Demandé par React)
     * GET /api/academic/teachers/{id}
     */
    public function showTeacher(string $id)
    {
        $teacher = Employee::where('status', 'active')
                            ->whereHas('position', function($query) {
                                $query->where('slug', 'enseignant');
                            })
                            ->find($id);

        if (!$teacher) {
            return response()->json(['message' => 'Enseignant introuvable ou inactif.'], 404);
        }

        return response()->json($teacher, 200);
    }

    /**
     * 2. FONCTION : Enregistrer l'attribution et son coefficient
     * POST /api/academic/attributions
     */
    public function storeAttribution(Request $request)
    {
        assert_writable_year();
        $validator = Validator::make($request->all(), [
            'employee_id'  => 'required|exists:employees,id',
            'classe_id'    => 'required|exists:classes,id',
            'subject_id'   => 'required|exists:subjects,id',
            'coefficient'  => 'required|integer|min:1|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $attribution = ClassroomSubjectTeacher::updateOrCreate(
            [
                'classe_id'  => $request->classe_id, // Vérifie bien que ta table pivot utilise 'classe_id' ou 'classes_id' selon ta migration
                'subject_id' => $request->subject_id,
            ],
            [
                'employee_id' => $request->employee_id,
                'coefficient' => $request->coefficient,
            ]
        );

        $attribution->load(['subject', 'classe.level']);

        return response()->json([
            'status' => 'success',
            'message' => 'Attribution et coefficient enregistrés avec succès !',
            'data' => $attribution
        ], 200);
    }

    /**
     * 3. FONCTION : Voir l'arbre pédagogique d'une classe (Matières, Profs, Coeffs)
     * GET /api/academic/classes/{classeId}/subjects
     */
    public function getClassSubjects(string $classeId)
    {
        $attributions = ClassroomSubjectTeacher::with(['subject', 'teacher'])
            ->where('classe_id', $classeId)
            ->get();

        return response()->json($attributions, 200);
    }

    /**
     * 3. Récupérer les attributions spécifiques d'un enseignant (Demandé par React)
     * GET /api/academic/teachers/{id}/attributions
     */
  /**
     * Récupérer les attributions spécifiques d'un enseignant (Demandé par React)
     * GET /api/academic/teachers/{id}/attributions
     */
    public function getTeacherAttributions(string $id)
    {
        try {
            // Sécurité : On s'assure que l'ID est un entier propre
            $teacherId = (int) $id;

            // On inclut 'subject' et 'classe' pour React. 
            // On peut aussi ajouter 'teacher' si ton UI en a besoin dans la liste.
            $attributions = ClassroomSubjectTeacher::with(['subject', 'classe.level']) 
                    ->where('employee_id', $teacherId) // ou 'classe_id' selon la fonction
                    ->get();

            return response()->json($attributions, 200);

        } catch (\Exception $e) {
            // En cas de pépin, on attrape l'erreur pour ne plus avoir une 500 anonyme
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    // Dans ton contrôleur Laravel
    public function destroy(string $id)
    {
        // On cherche l'attribution par son ID brut
        $attribution = ClassroomSubjectTeacher::find($id);

        // Si elle n'existe pas, on renvoie une réponse claire au lieu d'un crash automatique
        if (!$attribution) {
            return response()->json([
                'status' => 'error',
                'message' => "L'attribution avec l'ID {$id} n'existe pas."
            ], 404);
        }

        $attribution->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Attribution supprimée avec succès.'
        ], 200);
    }
}
