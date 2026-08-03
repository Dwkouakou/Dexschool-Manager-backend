<?php

namespace App\Http\Controllers;


use App\Http\Requests\StoreAcademicYearRequest;
use App\Models\Academic\AcademicYears;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AcademicYearsController extends Controller
{
    public function index(Request $request)
    {
        // ─── CORRECTIF : current_establishment_id() (pas establishment_id brut)
        // — sinon un admin ayant switché vers un établissement affilié verrait
        // toujours les années de son établissement D'ORIGINE, jamais celles
        // de l'établissement qu'il consulte réellement. ───
        $academicData = AcademicYears::where('establishment_id', current_establishment_id())
            ->orderByDesc('is_active')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            "status" => "success",
            "academicData" => $academicData
        ]);
    }

    public function AddAcdemicYears(StoreAcademicYearRequest $academicrequest)
    {
        try {
            $user = Auth::user();
            $establishmentId = current_establishment_id();
            $validated = $academicrequest->validated();

            $isActive = $validated['is_active'] ?? false;

            // Si la nouvelle année est active, on désactive les autres UNIQUEMENT dans cet établissement
            if ($isActive) {
                AcademicYears::where('establishment_id', $establishmentId)
                    ->update(['is_active' => false]);
            }

            $academicYear = AcademicYears::create([
                'establishment_id' => $establishmentId,
                'name'        => $validated['name'],
                'start_date'  => $validated['start_date'],
                'end_date'    => $validated['end_date'],
                "is_active"   => $isActive,
                "is_archived" => $validated['is_archived'] ?? false,
                "created_by"  => $user->id,
                "updated_by"  => null
            ]);

            return response()->json([
                "status" => "success",
                "message" => "L'année académique a été créée avec succès !",
                "academicData" => $academicYear
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                "status" => "error",
                "message" => "Impossible de créer l'année académique.",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, string $id)
    {
        $donnees_academique = AcademicYears::where('id', $id)
            ->where('establishment_id', current_establishment_id())
            ->firstOrFail();

        return response()->json([
            "status" => "success",
            "donnees_academique" => $donnees_academique
        ]);
    }

    public function update(Request $request, string $id)
    {
        try {
            $user = Auth::user();
            $establishmentId = current_establishment_id();

            $academicYear = AcademicYears::where('id', $id)
                ->where('establishment_id', $establishmentId)
                ->firstOrFail();

            $validated = $request->validate([
                'name'        => 'required|string|max:100|unique:academic_years,name,' . $id . ',id,establishment_id,' . $establishmentId,
                'start_date'  => 'required|date',
                'end_date'    => 'required|date|after:start_date',
                'is_active'   => 'required|boolean',
                'is_archived' => 'required|boolean',
            ]);

            if ($validated['is_active']) {
                AcademicYears::where('establishment_id', $establishmentId)
                    ->where('id', '!=', $id)
                    ->update(['is_active' => false]);
            }

            $academicYear->update([
                'name'        => $validated['name'],
                'start_date'  => $validated['start_date'],
                'end_date'    => $validated['end_date'],
                'is_active'   => $validated['is_active'],
                'is_archived' => $validated['is_archived'],
                'updated_by'  => $user->id,
            ]);

            return response()->json([
                "status" => "success",
                "message" => "L'année académique a été mise à jour avec succès !",
                "donnees_academique" => $academicYear
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
                "message" => "Impossible de modifier l'année académique.",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    public function delete(Request $request, string $id)
    {
        try {
            $deleteYears = AcademicYears::where('id', $id)
                ->where('establishment_id', current_establishment_id())
                ->firstOrFail();

            $deleteYears->delete();

            return response()->json([
                "status" => "success",
                "message" => "L'année scolaire a été supprimée avec succès."
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                "status" => "error",
                "message" => "Impossible de supprimer l'année scolaire.",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    public function getActiveYear(Request $request)
    {
        // ─── LE VRAI CORRECTIF DE CETTE SESSION : c'est CETTE méthode
        // précisément qui alimentait /academic-years/active, utilisée par
        // le formulaire de création d'inscription — d'où le mauvais
        // academic_year_id envoyé pour un établissement affilié. ───
        $activeYear = AcademicYears::where('establishment_id', current_establishment_id())
            ->where('is_active', true)
            ->where('is_archived', false)
            ->first();

        if (!$activeYear) {
            return response()->json([
                'status'  => 'error',
                'message' => "Aucune année académique active n'est configurée pour le moment."
            ], 404);
        }

        return response()->json($activeYear, 200);
    }

    public function getNextYear(Request $request)
    {
        $establishmentId = current_establishment_id();

        $nextYear = AcademicYears::where('establishment_id', $establishmentId)
            ->where('is_active', 0)
            ->where('is_archived', 0)
            ->where('start_date', '>', now())
            ->orderBy('start_date', 'asc')
            ->first();

        if (!$nextYear) {
            $nextYear = AcademicYears::where('establishment_id', $establishmentId)
                ->where('is_active', 1)
                ->first();
        }

        if (!$nextYear) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Aucune future année scolaire configurée.'
            ], 404);
        }

        return response()->json($nextYear, 200);
    }

    // ─────────────────────────────────────────────────────────────────
    // ACTIVATION D'UNE ANNÉE — réservée à l'Admin de l'établissement
    // Toutes les autres années de CET établissement sont désactivées.
    // ─────────────────────────────────────────────────────────────────
    public function activate(Request $request, string $id)
    {
        $establishmentId = current_establishment_id();

        $year = AcademicYears::where('id', $id)
            ->where('establishment_id', $establishmentId)
            ->first();

        if (!$year) {
            return response()->json([
                'status'  => 'error',
                'message' => "Cette année scolaire n'appartient pas à votre établissement."
            ], 404);
        }

        DB::transaction(function () use ($year, $establishmentId) {
            AcademicYears::where('establishment_id', $establishmentId)
                ->where('id', '!=', $year->id)
                ->update(['is_active' => false]);

            $year->update(['is_active' => true]);
        });

        return response()->json([
            'status'  => 'success',
            'message' => "L'année \"{$year->name}\" est maintenant l'année active de l'établissement.",
            'academicData' => $year->fresh(),
        ]);
    }
}