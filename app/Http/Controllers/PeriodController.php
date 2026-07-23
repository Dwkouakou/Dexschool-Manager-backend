<?php

namespace App\Http\Controllers;

use App\Models\Academic\Period;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator; 

class PeriodController extends Controller
{
    //

     /**
     * Récupérer toutes les périodes d'une année scolaire.
     * GET /api/academic/periods?academic_year_id=1
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'academic_year_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $periods = Period::where('academic_year_id', $request->academic_year_id)
                         ->orderBy('id', 'asc')
                         ->get();

        return response()->json($periods, 200);
    }

    /**
     * Initialiser automatiquement les périodes (Trimestres ou Semestres).
     * POST /api/academic/periods/generate
     */
    public function generate(Request $request)
    {   
         assert_writable_year();
        $validator = Validator::make($request->all(), [
            'academic_year_id' => 'required|integer',
            'type' => 'required|in:trimestre,semestre',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $academicYearId = $request->academic_year_id;
        $type = $request->type;

        // Éviter les doublons
        $exists = Period::where('academic_year_id', $academicYearId)->exists();
        if ($exists) {
            return response()->json([
                'status' => 'error',
                'message' => 'Les périodes pour cette année scolaire ont déjà été configurées.'
            ], 400);
        }

        try {
            $generatedPeriods = DB::transaction(function () use ($academicYearId, $type) {
                if ($type === 'trimestre') {
                    $data = [
                        ['name' => '1er Trimestre', 'code' => 'T1', 'is_active' => true],
                        ['name' => '2ème Trimestre', 'code' => 'T2', 'is_active' => false],
                        ['name' => '3ème Trimestre', 'code' => 'T3', 'is_active' => false],
                    ];
                } else {
                    $data = [
                        ['name' => '1er Semestre', 'code' => 'S1', 'is_active' => true],
                        ['name' => '2ème Semestre', 'code' => 'S2', 'is_active' => false],
                    ];
                }

                $inserted = [];
                foreach ($data as $item) {
                    $inserted[] = Period::create([
                        'academic_year_id' => $academicYearId,
                        'type' => $type,
                        'name' => $item['name'],
                        'code' => $item['code'],
                        'is_active' => $item['is_active'],
                    ]);
                }
                return $inserted;
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Périodes scolaires initialisées avec succès !',
                'data' => $generatedPeriods
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur interne est survenue lors de la génération.'
            ], 500);
        }
    }

    /**
     * Activer une période spécifique et couper les autres.
     * POST /api/academic/periods/{id}/activate
     */
    public function activate(string $id)
    {
        $period = Period::find($id);

        if (!$period) {
            return response()->json(['status' => 'error', 'message' => 'Période introuvable.'], 404);
        }

        try {
            DB::transaction(function () use ($period) {
                // Désactiver les périodes de la même année
                Period::where('academic_year_id', $period->academic_year_id)
                      ->update(['is_active' => false]);

                // Activer la période demandée
                $period->update(['is_active' => true]);
            });

            return response()->json([
                'status' => 'success',
                'message' => "Le {$period->name} est désormais la période active de l'établissement.",
                'active_period_id' => $period->id
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Impossible de modifier la période active.'
            ], 500);
        }
    }

    /**
     * Mettre à jour les dates d'une période.
     * PUT /api/academic/periods/{id}
     */
    public function update(Request $request, string $id)
    {   
         assert_writable_year();
        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $period = Period::find($id);

        if (!$period) {
            return response()->json(['status' => 'error', 'message' => 'Période introuvable.'], 404);
        }

        $period->update([
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Dates de la période mises à jour avec succès.',
            'data' => $period
        ], 200);
    }
}
