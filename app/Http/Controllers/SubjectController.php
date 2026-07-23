<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Academic\Subject;
use Illuminate\Support\Facades\Validator;

class SubjectController extends Controller
{
    //
     /**
     * 1. FONCTION : Lister toutes les matières
     * URL associée : GET /api/academic/subjects
     * Utilité : Alimente le tableau et les dropdowns côté React.
     */
    public function index()
    {
        // On récupère toutes les matières triées par ordre alphabétique
        $subjects = Subject::orderBy('name', 'asc')->get();
        
        // On retourne la liste en JSON pur avec un code HTTP 200 (OK)
        return response()->json($subjects, 200);
    }

    /**
     * 2. FONCTION : Enregistrer une nouvelle matière
     * URL associée : POST /api/academic/subjects
     * Utilité : Reçoit les données du formulaire JSX, les valide et les stocke.
     */
    public function store(Request $request)
    {
        // Validation stricte des données reçues du formulaire React
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:20|unique:subjects,code', // Code unique (ex: MATH, FR)
        ]);

        // Si la validation échoue, on renvoie les erreurs au format JSON
        // Votre fonction React ApiRequest interceptera le code 422 proprement
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Création de la matière dans la base de données
        $subject = Subject::create([
            'name' => $request->name,
            // On force le code en majuscules automatique pour la propreté (ex: math -> MATH)
            'code' => strtoupper($request->code), 
            'is_active' => true
        ]);

        // Retour de succès pour informer l'interface React
        return response()->json([
            'status' => 'success',
            'message' => 'Matière créée avec succès !',
            'data' => $subject
        ], 201); // Code 201 : Ressource créée
    }

    // Ajouter dans SubjectController.php
    public function update(Request $request, string $id)
    {
        $subject = Subject::findOrFail($id);
        
        $validator = Validator::make($request->all(), [
            'name'      => 'sometimes|string|max:255',
            'code'      => "sometimes|string|max:20|unique:subjects,code,{$id}",
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $subject->update([
            'name'      => $request->name      ?? $subject->name,
            'code'      => $request->code      ? strtoupper($request->code) : $subject->code,
            'is_active' => $request->has('is_active') ? $request->is_active : $subject->is_active,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Matière mise à jour.',
            'data'    => $subject,
        ], 200);
    }

    public function destroy(string $id)
    {
        $subject = Subject::findOrFail($id);

        // Vérifier si la matière est utilisée dans des notes avant suppression
        // if ($subject->grades()->exists()) {
        //     return response()->json(['message' => 'Impossible — matière utilisée dans des notes.'], 422);
        // }

        $subject->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Matière supprimée.',
        ], 200);
    }
}
