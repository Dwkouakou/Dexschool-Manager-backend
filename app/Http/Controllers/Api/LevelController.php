<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLevelRequest;
use App\Models\Academic\Cycle;
use App\Models\Academic\Level;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LevelController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $getCycles = Cycle::orderBy('is_active', 'desc') 
                            ->orderBy('created_at', 'asc')
                            ->get();

            return response()->json([
                "status" => "success",
                "cycles" => $getCycles  
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                "status" => "error",
                "message" => "Erreur backend lors de la récupération",
                "error" => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreLevelRequest $request)
    {
        //

        try {
        // Récupère les données nettoyées et validées par le StoreLevelRequest
            $validated = $request->validated();

            // Création du niveau en base de données
            $level = Level::create([
                'cycle_id'  => $validated['cycle_id'],
                'name'      => $validated['name'],
                'code'      => $validated['code'],
                'order'     => $validated['order'] ?? 0,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            // Retourne la réponse attendue par votre composant React
            return response()->json([
                "status" => "success",
                "message" => "Le niveau '{$level->name}' a été créé avec succès !",
                "academicData" => $level
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Intercepte les erreurs de doublons (couple cycle_id + code déjà pris)
            return response()->json([
                "status" => "error",
                "message" => "Ce code de niveau existe déjà pour ce cycle.",
                "errors" => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            // Intercepte les pannes de base de données
            return response()->json([
                "status" => "error",
                "message" => "Impossible de créer le niveau scolaire, veuillez réessayer.",
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
        try {
            $level = Level::findOrFail($id);

            $validated = $request->validate([
                'cycle_id'  => ['required', 'exists:cycles,id'],
                'name'      => ['required', 'string', 'max:100'],
                'order'     => ['nullable', 'integer'],
                'is_active' => ['required', 'boolean'],
                'code'      => [
                    'required',
                    'string',
                    'max:20',
                    // Unicité combinée : ignore l'enregistrement actuel lors de la vérification
                    Rule::unique('levels')->where(function ($query) use ($request) {
                        return $query->where('cycle_id', $request->cycle_id);
                    })->ignore($id)
                ],
            ]);

            $level->update($validated);

            // Recharge la relation cycle pour que l'affichage React soit à jour
            $level->load('cycle');

            return response()->json([
                "status" => "success",
                "message" => "Le niveau scolaire a été mis à jour avec succès !",
                "academicData" => $level
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                "status" => "error",
                "message" => "Ce code existe déjà pour ce cycle.",
                "errors" => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                "status" => "error",
                "message" => "Impossible de modifier le niveau.",
                "error" => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //

            try {
           
            $level = Level::findOrFail($id);
            $level->delete();

            return response()->json([
                "status" => "success",
                "message" => "Le niveau scolaire a été supprimé avec succès."
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                "status" => "error",
                "message" => "Ce niveau scolaire n'existe pas ou a déjà été supprimé."
            ], 404);

        } catch (\Exception $e) {
         
            return response()->json([
                "status" => "error",
                "message" => "Impossible de supprimer ce niveau car il est actuellement utilisé dans le système.",
                "error" => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Récupère la liste de tous les niveaux avec leur cycle parent
     */
    public function getLevelsList()
    {
        try {
            // Charge tous les niveaux triés par ordre, en incluant les données du cycle lié
            $levels = Level::with('cycle')
                        ->orderBy('order', 'asc')
                        ->orderBy('created_at', 'desc')
                        ->get();

            return response()->json([
                "status" => "success",
                "academicDataLevels" => $levels
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                "status" => "error",
                "message" => "Impossible de récupérer la liste des niveaux.",
                "error" => $e->getMessage()
            ], 500);
        }
    }



}
