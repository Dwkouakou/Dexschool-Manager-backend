<?php

namespace App\Http\Controllers\Api\Academic;

use App\Http\Controllers\Controller;
use App\Models\Academic\Period;
use App\Models\Academic\PeriodAverage;
use App\Models\Academic\SubjectAverage;
use App\Models\Academic\ClassroomSubjectTeacher;
use App\Models\Academic\ReportCard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReportCardController extends Controller
{
    /**
     * 1. FONCTION : Lister les bulletins calculés et validés d'une classe
     * GET /api/academic/report-cards?classe_id=1
     */
    public function index(Request $request)
    {
        $request->validate(['classe_id' => 'required|exists:classes,id']);

        $activePeriod = Period::where('is_active', true)->first();
        if (!$activePeriod) {
            return response()->json(['status' => 'error', 'message' => 'Aucune période active.'], 422);
        }

        // On récupère les bilans généraux validés ou non par le conseil
        $bulletins = PeriodAverage::where('classe_id', $request->classe_id)
            ->where('period_id', $activePeriod->id)
            ->join('students', 'period_averages.student_id', '=', 'students.id')
            ->select('period_averages.*', 'students.first_name', 'students.last_name', 'students.matricule')
            ->orderBy('general_average', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'period_name' => $activePeriod->name,
            'data' => $bulletins
        ], 200);
    }

    /**
     * 2. FONCTION : Charger toutes les données d'UN bulletin (Pour l'impression)
     * GET /api/academic/report-cards/student/{studentId}
     */
    public function getReportCardData(Request $request, $studentId)
    {
        $request->validate(['classe_id' => 'required|exists:classes,id']);
        $classeId = $request->classe_id;

        $activePeriod = Period::where('is_active', true)->first();
        if (!$activePeriod) {
            return response()->json(['status' => 'error', 'message' => 'Aucune période active.'], 422);
        }

        // 1. Infos générales de l'élève (Moyenne générale, Rang)
        $generalInfo = PeriodAverage::where('student_id', $studentId)
            ->where('classe_id', $classeId)
            ->where('period_id', $activePeriod->id)
            ->join('students', 'period_averages.student_id', '=', 'students.id')
            ->select('period_averages.*', 'students.first_name', 'students.last_name', 'students.matricule')
            ->first();

        if (!$generalInfo) {
            return response()->json(['status' => 'error', 'message' => 'Moyennes non calculées pour cet élève.'], 404);
        }

        // 2. Récupérer les coefficients de la classe
        $attributions = ClassroomSubjectTeacher::where('classe_id', $classeId)->get();

        // 3. Détail des notes par matière
        $subjectDetails = SubjectAverage::where('student_id', $studentId)
            ->where('classe_id', $classeId)
            ->where('period_id', $activePeriod->id)
            ->join('subjects', 'subject_averages.subject_id', '=', 'subjects.id')
            ->select('subject_averages.*', 'subjects.name as subject_name', 'subjects.code as subject_code')
            ->get()
            ->map(function($item) use ($attributions) {
                // On injecte dynamiquement le coefficient pour que React puisse faire l'affichage du bulletin propre
                $coeff = $attributions->where('subject_id', $item->subject_id)->first()->coefficient ?? 1;
                $item->coefficient = $coeff;
                $item->total_points = $item->average * $coeff;
                return $item;
            });

        // 4. Traçabilité (Création ou récupération de la référence du bulletin)
        $reportCardRecord = ReportCard::firstOrCreate(
            ['period_average_id' => $generalInfo->id],
            ['reference' => 'BL-' . date('Y') . '-' . $activePeriod->code . '-' . Str::upper(Str::random(5))]
        );

        return response()->json([
            'status' => 'success',
            'school_name' => 'DexSchool Manager (Yakro)',
            'period_name' => $activePeriod->name,
            'reference' => $reportCardRecord->reference,
            'student_info' => $generalInfo,
            'subjects' => $subjectDetails
        ], 200);
    }
}
