<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClassesValidation;
use App\Models\Academic\AcademicYears;
use App\Models\Academic\Classe;
use App\Models\Academic\Cycle;
use App\Models\Academic\Level;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ClasseController extends Controller
{
    public function index()
    {
        try {

           $getYears = AcademicYears::where('establishment_id', current_establishment_id())
                        ->orderBy('is_active', 'desc')
                        ->orderBy("created_at", "desc")
                        ->get();

            return response()->json([
                "status" => "success",
                "years" => $getYears
            ], 200);

        }catch(\Exception $e){
            return response()->json([
                "status" => "error",
                "message" => "Erreur backend lors de la recuperation",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    public function levelsCycle () {
        try{

            $getAllLevelsWithCycles = Level::with('cycle')
            ->orderBy('order', 'asc')
            ->orderBy('created_at', 'desc')
            ->where("is_active", 1)
                            ->get();

            return response()->json([
                "status" => "success",
                "LevelWithCycles" => $getAllLevelsWithCycles
            ], 200);

        }catch(\Exception $e){
            return response()->json([
                'status' => "error",
                "message" => "Une erreur s'est produite lors de la recuperation des cycles et niveaux",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    public function store(StoreClassesValidation $request)
    {
        try {
            $validated = $request->validated();

            $academicClass = Classe::create([
                'level_id'          => $validated['level_id'],
                'academic_year_id'  => $validated['academic_year_id'],
                'name'              => $validated['name'],
                'code'              => $validated['code'],
                'capacity'          => $validated['capacity'],
                'classroom'         => $validated['classroom'] ?? null,
                'is_active'         => $validated['is_active'] ?? true,
                'main_teacher_id'   => null,
            ]);

            $academicClass->load(['level.cycle', 'academicYear']);

            return response()->json([
                "status" => "success",
                "message" => "La classe '{$academicClass->name}' a été configurée et créée avec succès !",
                "academicData" => $academicClass
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                "status" => "error",
                "message" => "Ce code de classe existe déjà pour ce niveau sur cette année scolaire.",
                "errors" => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                "status" => "error",
                "message" => "Impossible de créer la classe, veuillez réessayer.",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        //
    }

    public function update(Request $request, string $id)
    {
        $classe = Classe::find($id);

        if (!$classe) {
            return response()->json([
                'status' => 'error',
                'message' => 'Classe introuvable.'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'level_id'         => 'required|integer|exists:levels,id',
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'name'             => 'required|string|max:100',
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('classes', 'code')
                    ->ignore($classe->id)
                    ->where(fn($q) => $q->where('establishment_id', current_establishment_id())),
            ],
            'capacity'         => 'required|integer|min:1',
            'classroom'        => 'nullable|string|max:255',
            'main_teacher_id'  => 'nullable|integer',
            'is_active'        => 'required|boolean',
        ], [
            'level_id.required'         => 'Le niveau est requis.',
            'level_id.exists'           => 'Le niveau sélectionné est invalide.',
            'academic_year_id.required' => 'L\'année scolaire est requise.',
            'academic_year_id.exists'   => 'L\'année scolaire sélectionnée est invalide.',
            'name.required'             => 'Le nom de la classe est requis.',
            'code.required'             => 'Le code est requis.',
            'code.unique'               => 'Ce code de classe est déjà utilisé dans l\'établissement.',
            'capacity.required'         => 'La capacité est requise.',
            'capacity.min'              => 'La capacité doit être d\'au moins 1 élève.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors()
            ], 422);
        }

        try {
            $classe->update([
                'level_id'         => $request->level_id,
                'academic_year_id' => $request->academic_year_id,
                'name'             => $request->name,
                'code'             => strtoupper($request->code),
                'capacity'         => $request->capacity,
                'classroom'        => $request->classroom,
                'main_teacher_id'  => $request->main_teacher_id,
                'is_active'        => $request->is_active,
            ]);

            $classe->load(['level', 'level.cycle', 'academicYear']);

            return response()->json([
                'status'  => 'success',
                'message' => 'La classe a été modifiée avec succès !',
                'classe'  => $classe
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur interne est survenue lors de la modification de la classe.'
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        $classe = Classe::find($id);

        if (!$classe) {
            return response()->json([
                'status' => 'error',
                'message' => 'Classe introuvable.'
            ], 404);
        }

        try {
            if ($classe->enrollments()->exists()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Impossible de supprimer cette classe car elle contient déjà des élèves inscrits. Désactivez-la plutôt via son statut.'
                ], 422);
            }

            $classe->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'La classe a été supprimée avec succès !'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la suppression de la classe.'
            ], 500);
        }
    }

    public function getClasses () {
         try {

                $AllClasses = Classe::with(['level.cycle', 'academicYear'])
                                            ->orderBy("is_active", "desc")
                                            ->orderBy("created_at", "asc")
                                            ->get();

                return response()->json([
                    "status" => "success",
                    "data"   => $AllClasses
                ], 200);

            } catch (\Exception $e) {
                return response()->json([
                    "status"  => "error",
                    "message" => "Impossible de récupérer les classes, veuillez réessayer.",
                    "error"   => $e->getMessage()
                ], 500);
            }
    }

    /**
     * Liste des classes disponibles pour le formulaire d'inscription, avec
     * le nombre RÉEL d'élèves inscrits par classe (utilisé pour bloquer la
     * sélection d'une classe pleine, et l'afficher dans le sélecteur).
     * GET /classesEnrollment
     */
    public function enrollmentClasses(Request $request)
    {
        $targetYearId = $request->query('academic_year_id') ?: current_active_year_id();

        $classes = Classe::with('level')
            ->withCount(['enrollments' => function ($query) use ($targetYearId) {
                // ─── CORRECTIF : une inscription ANNULÉE ne doit jamais
                // compter comme occupant une place — sinon la classe paraît
                // à tort pleine (ou plus remplie qu'en réalité) après une
                // annulation, empêchant d'y réinscrire quelqu'un d'autre
                // alors qu'une place est réellement libre. ───
                $query->where('academic_year_id', $targetYearId)
                      ->where('status', '!=', 'cancelled');
            }])
            ->where('academic_year_id', $targetYearId)
            ->where('is_active', true)
            ->get();

        $formattedClasses = $classes->map(function ($classe) {
            return [
                'id'                => $classe->id,
                'name'              => $classe->name,
                'code'              => $classe->code,
                'level'             => $classe->level ? $classe->level->name : 'N/A',
                'capacity'          => (int) $classe->capacity,
                'enrollments_count' => (int) $classe->enrollments_count,
            ];
        });

        return response()->json($formattedClasses, 200);
    }

    /**
     * Aperçu : liste des classes de l'année ACTIVE, prêtes à être dupliquées
     * vers l'année cible (non active). GET /academic-years/{targetYearId}/duplicate-classes-preview
     */
    public function duplicateClassesPreview(string $targetYearId)
    {
        $establishmentId = current_establishment_id();

        $targetYear = AcademicYears::where('id', $targetYearId)
            ->where('establishment_id', $establishmentId)
            ->first();

        if (!$targetYear) {
            return response()->json(['status' => 'error', 'message' => "Année introuvable."], 404);
        }

        if ($targetYear->is_active) {
            return response()->json(['status' => 'error', 'message' => "Cette année est déjà active."], 422);
        }

        $activeYearId = current_active_year_id();
        if (!$activeYearId) {
            return response()->json(['status' => 'error', 'message' => "Aucune année active à dupliquer."], 422);
        }

        $existingCodes = Classe::where('academic_year_id', $targetYearId)
            ->pluck('code')
            ->toArray();

        $sourceClasses = Classe::with('level.cycle')
            ->where('academic_year_id', $activeYearId)
            ->orderBy('name')
            ->get()
            ->map(function ($c) use ($existingCodes) {
                return [
                    'id'               => $c->id,
                    'name'             => $c->name,
                    'code'             => $c->code,
                    'capacity'         => $c->capacity,
                    'level_id'         => $c->level_id,
                    'level_name'       => $c->level?->name,
                    'cycle_name'       => $c->level?->cycle?->name,
                    'classroom'        => $c->classroom,
                    'already_exists'   => in_array($c->code, $existingCodes),
                ];
            });

        return response()->json([
            'status'       => 'success',
            'source_year'  => AcademicYears::find($activeYearId)?->name,
            'target_year'  => $targetYear->name,
            'classes'      => $sourceClasses,
        ]);
    }

    /**
     * Duplique réellement les classes sélectionnées vers l'année cible,
     * avec capacité éventuellement ajustée par l'admin.
     * POST /academic-years/{targetYearId}/duplicate-classes
     */
    public function duplicateClasses(Request $request, string $targetYearId)
    {
        $establishmentId = current_establishment_id();

        $validated = $request->validate([
            'classes'              => ['required', 'array', 'min:1'],
            'classes.*.id'         => ['required', 'integer', 'exists:classes,id'],
            'classes.*.capacity'   => ['required', 'integer', 'min:1'],
        ]);

        $targetYear = AcademicYears::where('id', $targetYearId)
            ->where('establishment_id', $establishmentId)
            ->first();

        if (!$targetYear || $targetYear->is_active) {
            return response()->json([
                'status'  => 'error',
                'message' => "Année cible invalide ou déjà active."
            ], 422);
        }

        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($validated, $targetYear, &$created, &$skipped) {
            foreach ($validated['classes'] as $item) {
                $sourceClass = Classe::find($item['id']);
                if (!$sourceClass) continue;

                $exists = Classe::where('academic_year_id', $targetYear->id)
                    ->where('level_id', $sourceClass->level_id)
                    ->where('code', $sourceClass->code)
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                Classe::create([
                    'level_id'         => $sourceClass->level_id,
                    'academic_year_id' => $targetYear->id,
                    'name'             => $sourceClass->name,
                    'code'             => $sourceClass->code,
                    'capacity'         => $item['capacity'],
                    'classroom'        => $sourceClass->classroom,
                    'main_teacher_id'  => null,
                    'is_active'        => true,
                ]);

                $created++;
            }
        });

        return response()->json([
            'status'  => 'success',
            'message' => "{$created} classe(s) créée(s) pour {$targetYear->name}" . ($skipped > 0 ? ", {$skipped} déjà existante(s) ignorée(s)." : "."),
            'created' => $created,
            'skipped' => $skipped,
        ]);
    }
}