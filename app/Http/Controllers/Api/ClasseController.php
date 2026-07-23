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
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
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

    /**
     * Store a newly created resource in storage.
     */
   public function store(StoreClassesValidation $request)
    {
        try {
            $validated = $request->validated();

          

            // if($verifLevelIsEnabled->is_active !== 1) {
            //     return response()->json([
            //         "status" => "error",
            //         "message" => "Impossible de créer la classe, veuillez activer le niveaux !",
                   
            //     ], 500);
            // }
            $academicClass = Classe::create([
                'level_id'          => $validated['level_id'],
                'academic_year_id'  => $validated['academic_year_id'],
                'name'              => $validated['name'],
                'code'              => $validated['code'],
                'capacity'          => $validated['capacity'],
                'classroom'         => $validated['classroom'] ?? null,
                'is_active'         => $validated['is_active'] ?? true,
                'main_teacher_id'   => null, // Isolé temporairement selon notre stratégie
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

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        // 1. Rechercher la classe existante
        $classe = Classe::find($id);

        if (!$classe) {
            return response()->json([
                'status' => 'error',
                'message' => 'Classe introuvable.'
            ], 404);
        }

        // 2. Validation des données reçues du formulaire React
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
            // Mise à jour de la classe avec les données validées
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

            // Recharger les relations pour renvoyer un objet complet au React 
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

    /**
     * Remove the specified resource from storage.
     */
//    public function destroy(string $id)
//     {
//         //  Rechercher la classe par son ID
//         $classe = Classe::find($id);

//         //  Si la classe n'existe pas, je retourne une erreur
//         if (!$classe) {
//             return response()->json([
//                 'status' => 'error',
//                 'message' => 'Classe introuvable.'
//             ], 404);
//         }

//         try {
//             // 3. Optionnel : Vérifier si des élèves ou des données y sont liés avant de supprimer
//             // Si vous avez une relation "students", vous pouvez décommenter ces lignes :
            
//             if ($classe->students()->exists()) {
//                 return response()->json([
//                     'status' => 'error',
//                     'message' => 'Impossible de supprimer cette classe car elle contient déjà des élèves inscrits. Désactivez-la plutôt.'
//                 ], 422);
//             }
            

//             // Supprimer l'enregistrement de la base de données
//             $classe->delete();

//             // 5. Retourner la réponse de succès attendue par votre React
//             return response()->json([
//                 'status' => 'success',
//                 'message' => 'La classe a été supprimée avec succès !'
//             ], 200);

//         } catch (\Exception $e) {
//             // En cas d'erreur serveur ou de contrainte d'intégrité de clé étrangère (SQL)
//             return response()->json([
//                 'status' => 'error',
//                 'message' => 'Impossible de supprimer cette classe car elle est liée à d\'autres éléments du système.'
//             ], 500);
//         }
//     }

    public function destroy(string $id)
{
    // 1. Rechercher la classe par son ID
    $classe = Classe::find($id);

    // 2. Si la classe n'existe pas, retourner une erreur
    if (!$classe) {
        return response()->json([
            'status' => 'error',
            'message' => 'Classe introuvable.'
        ], 404);
    }

    try {
        // 3. SÉCURITÉ : Vérifier s'il y a des inscriptions liées à cette classe
        if ($classe->enrollments()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Impossible de supprimer cette classe car elle contient déjà des élèves inscrits. Désactivez-la plutôt via son statut.'
            ], 422); // Code 422 : Entité non traitable (Erreur logique métier)
        }

        // 4. Supprimer la classe si elle est totalement vide
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

    //  public function enrollmentClasses()
    // {
    //     // On charge la relation 'level' pour récupérer son 'name' (Petite Section, CP1, etc.)
    //     $classes = Classe::with('level')
    //         ->where('is_active', "1")
    //         ->get();

    //     // Transformation légère pour correspondre aux propriétés attendues par Claude (level: c.level.name)
    //     $formattedClasses = $classes->map(function ($classe) {
    //         return [
    //             'id'    => $classe->id,
    //             'name'  => $classe->name, // ex: CP1 A
    //             'code'  => $classe->code,
    //             'level' => $classe->level ? $classe->level->name : 'N/A', // Transmet le libellé textuel
    //         ];
    //     });

    //     return response()->json($formattedClasses, 200);
    // }

    // public function enrollmentClasses()
    // {
    //     // 1. Récupérer l'année académique active pour isoler les inscriptions en cours
    //     $activeYear = AcademicYears::where('is_active', true)->first();
    //     $activeYearId = current_active_year_id();

    //     // 2. Charger les classes actives, leur niveau, et compter les inscriptions de cette année active
    //     $classes = Classe::with('level')
    //         ->withCount(['enrollments' => function ($query) use ($activeYearId) {
    //             if ($activeYearId) {
    //                 $query->where('academic_year_id', $activeYearId);
    //             }
    //         }])
    //         ->where('is_active', true) // Filtre uniquement les classes actives
    //         ->get();

    //     // 3. Formater les données pour le composant React (CreateEnrollmentPage)
    //     $formattedClasses = $classes->map(function ($classe) {
    //         return [
    //             'id'                => $classe->id,
    //             'name'              => $classe->name, // ex: CP1 A
    //             'code'              => $classe->code,
    //             'level'             => $classe->level ? $classe->level->name : 'N/A', // Libellé textuel du niveau
    //             'capacity'          => (int) $classe->capacity, // Transmis en entier pour la comparaison React
    //             'enrollments_count' => (int) $classe->enrollments_count, // Nombre actuel d'élèves inscrits
    //         ];
    //     });

    //     return response()->json($formattedClasses, 200);
    // }

    public function enrollmentClasses(Request $request)
{
    // Utilise l'année passée en paramètre, sinon repli sur l'année active
    $targetYearId = $request->query('academic_year_id') ?: current_active_year_id();

    $classes = Classe::with('level')
        ->withCount(['enrollments' => function ($query) use ($targetYearId) {
            $query->where('academic_year_id', $targetYearId);
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

        // Classes déjà existantes dans l'année cible (pour éviter les doublons visuels)
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

                // Évite le doublon : même niveau + même code déjà présents sur l'année cible
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
                    'capacity'         => $item['capacity'], // capacité éventuellement ajustée
                    'classroom'        => $sourceClass->classroom,
                    'main_teacher_id'  => null, // l'affectation prof se refait pour la nouvelle année
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
