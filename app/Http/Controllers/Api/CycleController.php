<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCycleRequest;
use App\Models\Academic\Cycle;
use Illuminate\Http\Request;

class CycleController extends Controller
{
    /**
     * Liste des cycles ordonnés de façon pédagogique
     */
    public function index()
    {
        try {
            // Utilisation du champ 'order' configuré plus tôt
            $cycles = Cycle::orderBy('order', 'asc')->get();

            return response()->json([
                "status" => "success",
                "academicDataCycle" => $cycles
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                "status" => "error",
                "message" => "Impossible de récupérer les cycles.",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Création d'un nouveau cycle
     */
    public function store(StoreCycleRequest $Cyclerequest)
    {
        try {
            $validated = $Cyclerequest->validated();
            $is_active = $validated["is_active"] ?? false;

            $createCycle = Cycle::create([
                "name"        => $validated['name'],
                "code"        => $validated["code"],
                "description" => $validated["description"] ?? null,
                "order"       => $validated["order"] ?? 0,
                "is_active"   => $is_active
            ]);

            return response()->json([
                "status"  => "success",
                "message" => "Le cycle {$createCycle->name} - code : {$createCycle->code} a bien été créé avec succès !",
                "academicData" => $createCycle
            ], 200); 

        } catch (\Exception $e) {
            return response()->json([
                "status"  => "error",
                "message" => "Impossible de créer le cycle scolaire, veuillez réessayer.",
                "error"   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Détails d'un cycle
     */
    public function show(string $id)
    {
        try {
            $cycle = Cycle::findOrFail($id);

            return response()->json([
                "status" => "success",
                "donnees_cycle" => $cycle   
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                "status" => "error",
                "message" => "Cycle introuvable.",
                "error" => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Mise à jour d'un cycle
     */
    public function update(Request $request, string $id)
    {
        try {
            $cycle = Cycle::findOrFail($id);

            $validated = $request->validate([
                'name'        => ['required', 'string', 'max:100'],
                'code' => [
                    'required', 'string', 'max:20',
                    \Illuminate\Validation\Rule::unique('cycles', 'code')
                        ->ignore($id)
                        ->where(fn($q) => $q->where('establishment_id', current_establishment_id())),
                ],
                'description' => ['nullable', 'string'],
                'order'       => ['nullable', 'integer'],
                'is_active'   => ['required', 'boolean'],
            ]);

            $cycle->update($validated);

            return response()->json([
                "status" => "success",
                "message" => "Le cycle a été mis à jour avec succès !",
                "academicData" => $cycle
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                "status" => "error",
                "message" => "Les données fournies sont invalides.",
                "errors" => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                "status" => "error",
                "message" => "Impossible de modifier le cycle.",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Suppression d'un cycle
     */
    public function destroy(string $id)
    {
        try {
            $cycle = Cycle::findOrFail($id);
            $cycle->delete(); // Grâce à cascadeOnDelete(), les niveaux liés sauteront aussi proprement

            return response()->json([
                "status" => "success",
                "message" => "Le cycle a été supprimé avec succès."
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                "status" => "error",
                "message" => "Impossible de supprimer ce cycle.",
                "error" => $e->getMessage()
            ], 500);
        }
    }
}
