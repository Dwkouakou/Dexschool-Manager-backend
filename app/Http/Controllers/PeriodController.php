<?php

namespace App\Http\Controllers;

use App\Models\Academic\AcademicYears;
use App\Models\Academic\Period;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PeriodController extends Controller
{
    /**
     * Année scolaire de l'établissement COURANT correspondant à l'id reçu, ou null.
     * (l'id vient du client : on ne lui fait jamais confiance sans ce contrôle)
     */
    private function ownYear($yearId): ?AcademicYears
    {
        if (!$yearId) return null;

        return AcademicYears::where('id', $yearId)
            ->where('establishment_id', current_establishment_id())
            ->first();
    }

    /**
     * GET /academic/periods?academic_year_id=1
     * Sans paramètre : année consultée par l'utilisateur.
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'academic_year_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $year = $this->ownYear($request->input('academic_year_id', current_viewing_year_id()));
        if (!$year) {
            return response()->json(['status' => 'error', 'message' => 'Année scolaire introuvable.'], 404);
        }

        $periods = Period::where('academic_year_id', $year->id)
                         ->orderBy('id', 'asc')
                         ->get();

        return response()->json($periods, 200);
    }

    /**
     * POST /academic/periods/generate
     */
    public function generate(Request $request)
    {
        assert_writable_year();

        $validator = Validator::make($request->all(), [
            'academic_year_id' => 'required|integer',
            'type'             => 'required|in:trimestre,semestre',
            'coefficients'     => 'nullable|array',
            'coefficients.*'   => 'required|numeric|min:0.1|max:99',
        ], [
            'coefficients.*.min'     => 'Chaque coefficient doit être au moins égal à 0,1.',
            'coefficients.*.numeric' => 'Les coefficients doivent être des nombres.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // L'année doit appartenir à l'établissement courant ET être l'année active
        $year = $this->ownYear($request->academic_year_id);
        if (!$year || (int) $year->id !== (int) current_active_year_id()) {
            return response()->json([
                'status'  => 'error',
                'message' => "Les périodes ne peuvent être initialisées que pour l'année scolaire active de l'établissement.",
            ], 422);
        }

        $type  = $request->type;
        $count = $type === 'trimestre' ? 3 : 2;
        $coefs = array_values($request->input('coefficients', array_fill(0, $count, 1)));

        if (count($coefs) !== $count) {
            return response()->json(['errors' => ['coefficients' => ["Il faut exactement $count coefficients."]]], 422);
        }

        try {
            $generated = DB::transaction(function () use ($year, $type, $coefs) {
                // Vérification DANS la transaction (verrou) : évite deux générations simultanées
                $exists = Period::where('academic_year_id', $year->id)->lockForUpdate()->exists();
                if ($exists) return null;

                $data = $type === 'trimestre'
                    ? [['1er Trimestre', 'T1', true], ['2ème Trimestre', 'T2', false], ['3ème Trimestre', 'T3', false]]
                    : [['1er Semestre', 'S1', true], ['2ème Semestre', 'S2', false]];

                $inserted = [];
                foreach ($data as $i => [$name, $code, $active]) {
                    $period = new Period();
                    // forceFill : fonctionne même si « coefficient » n'est pas encore dans $fillable
                    $period->forceFill([
                        'academic_year_id' => $year->id,
                        'type'             => $type,
                        'name'             => $name,
                        'code'             => $code,
                        'coefficient'      => $coefs[$i],
                        'is_active'        => $active,
                    ])->save();
                    $inserted[] = $period;
                }
                return $inserted;
            });

            if ($generated === null) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Les périodes pour cette année scolaire ont déjà été configurées.',
                ], 409);
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Périodes scolaires initialisées avec succès !',
                'data'    => $generated,
            ], 201);

        } catch (\Throwable $e) {
            Log::error('Génération des périodes : ' . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur interne est survenue lors de la génération.',
            ], 500);
        }
    }

    /**
     * POST /academic/periods/{id}/activate
     */
    public function activate(string $id)
    {
        assert_writable_year();

        $period = Period::find($id);

        if (!$period) {
            return response()->json(['status' => 'error', 'message' => 'Période introuvable.'], 404);
        }

        try {
            DB::transaction(function () use ($period) {
                Period::where('academic_year_id', $period->academic_year_id)->update(['is_active' => false]);
                $period->update(['is_active' => true]);
            });

            return response()->json([
                'status'           => 'success',
                'message'          => "Le {$period->name} est désormais la période active de l'établissement.",
                'active_period_id' => $period->id,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Activation de période : ' . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Impossible de modifier la période active.',
            ], 500);
        }
    }

    /**
     * PUT /academic/periods/{id}
     * Champs optionnels (seuls ceux envoyés sont modifiés) :
     *  - start_date + end_date : dans l'année scolaire, sans chevauchement (ou les deux à null pour effacer)
     *  - coefficient           : poids de la période dans la moyenne annuelle
     */
    public function update(Request $request, string $id)
    {
        assert_writable_year();

        $validator = Validator::make($request->all(), [
            'start_date'  => 'nullable|date',
            'end_date'    => 'nullable|date|after_or_equal:start_date',
            'coefficient' => 'sometimes|numeric|min:0.1|max:99',
        ], [
            'coefficient.min'     => 'Le coefficient doit être au moins égal à 0,1.',
            'coefficient.numeric' => 'Le coefficient doit être un nombre.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $period = Period::find($id);

        if (!$period) {
            return response()->json(['status' => 'error', 'message' => 'Période introuvable.'], 404);
        }

        $changes = [];

        if ($request->has('start_date') || $request->has('end_date')) {
            $start = $request->start_date ? \Carbon\Carbon::parse($request->start_date)->startOfDay() : null;
            $end   = $request->end_date   ? \Carbon\Carbon::parse($request->end_date)->startOfDay()   : null;

            if (($start && !$end) || (!$start && $end)) {
                return response()->json(['errors' => ['start_date' => ['Renseignez la date de début ET la date de fin.']]], 422);
            }

            if ($start) {
                $year = $this->ownYear($period->academic_year_id);
                if ($year && $year->start_date && $year->end_date
                    && ($start->lt($year->start_date->copy()->startOfDay()) || $end->gt($year->end_date->copy()->startOfDay()))) {
                    return response()->json(['errors' => ['start_date' => [
                        "Les dates doivent être comprises dans l'année scolaire ("
                        . $year->start_date->format('d/m/Y') . ' → ' . $year->end_date->format('d/m/Y') . ').',
                    ]]], 422);
                }

                $overlap = Period::where('academic_year_id', $period->academic_year_id)
                    ->where('id', '!=', $period->id)
                    ->whereNotNull('start_date')->whereNotNull('end_date')
                    ->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)
                    ->first();

                if ($overlap) {
                    return response()->json(['errors' => ['start_date' => [
                        "Ces dates chevauchent « {$overlap->name} »."]]], 422);
                }
            }

            $changes['start_date'] = $start;
            $changes['end_date']   = $end;
        }

        if ($request->has('coefficient')) {
            $changes['coefficient'] = round((float) $request->coefficient, 2);
        }

        if ($changes) {
            $period->forceFill($changes)->save();
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Période mise à jour avec succès.',
            'data'    => $period->fresh(),
        ], 200);
    }
}