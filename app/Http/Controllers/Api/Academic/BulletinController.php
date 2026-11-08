<?php

namespace App\Http\Controllers\Api\Academic;

use App\Http\Controllers\Controller;
use App\Models\Academic\AcademicYears;
use App\Models\Academic\Classe;
use App\Models\Academic\Period;
use App\Models\Establishment;
use App\Services\ClassResultsService as R;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Module « Bulletin élève » — onglet « Bulletin de notes ».
 * Toutes les données viennent de ClassResultsService (calcul à la volée, coefficients du module Matières).
 */
class BulletinController extends Controller
{
    private function deny()
    {
        $u = Auth::user();
        $ok = $u && ($u->roles()->where('slug', 'admin')->exists() || $u->can('grades.view_all'));
        return $ok ? null : response()->json(['message' => "Cette section est réservée à l'administration."], 403);
    }

    private function resolve(Request $request)
    {
        $v = Validator::make($request->all(), ['classe_id' => 'required|integer', 'period_id' => 'required|integer']);
        if ($v->fails()) return ['error' => response()->json(['errors' => $v->errors()], 422)];
        $classe = Classe::with('level')->find($request->classe_id);
        $period = Period::find($request->period_id);
        if (!$classe) return ['error' => response()->json(['message' => 'Classe introuvable.'], 404)];
        if (!$period) return ['error' => response()->json(['message' => 'Période introuvable.'], 404)];
        return compact('classe', 'period');
    }

    private function establishment(): array
    {
        $e = Establishment::find(current_establishment_id());
        $y = AcademicYears::find(current_viewing_year_id());
        return [
            'name' => $e->name ?? '', 'code' => $e->code ?? '', 'address' => $e->address ?? '', 'phone' => $e->phone ?? '',
            'director_name' => $e->director_name ?? '', 'director_title' => $e->director_title ?? 'Directeur', 'logo' => $e->logo ?? null,
            'academic_year' => $y->name ?? '',
        ];
    }

    /** GET /report-cards/context */
    public function context()
    {
        if ($d = $this->deny()) return $d;
        $periods = Period::where('academic_year_id', current_viewing_year_id())->orderBy('id')->get(['id', 'name', 'code', 'is_active']);
        return response()->json([
            'periods' => $periods,
            'active_period_id' => $periods->firstWhere('is_active', true)?->id,
            'classes' => Classe::orderBy('name')->get(['id', 'name', 'level_id']),
            'levels'  => Classe::with('level')->get()->pluck('level')->filter()->unique('id')->sortBy('name')->map(fn ($l) => ['id' => $l->id, 'name' => $l->name])->values(),
            'establishment' => $this->establishment(),
        ], 200);
    }

    private function row($res, $s): array
    {
        $g = $res['general'][$s->id] ?? null;
        return [
            'student_id' => $s->id, 'matricule' => $s->national_matricule ?: $s->matricule,
            'last_name' => $s->last_name, 'first_name' => $s->first_name,
            'repeater' => (bool) ($res['repeaters'][$s->id] ?? false),
            'average' => $g, 'rank' => $res['rank'][$s->id]['rank'] ?? null, 'tie' => $res['rank'][$s->id]['tie'] ?? false,
            'appreciation' => R::appreciation($g),
        ];
    }

    /** GET /report-cards/class?classe_id=&period_id= */
    public function classSummary(Request $request)
    {
        if ($d = $this->deny()) return $d;
        $r = $this->resolve($request);
        if (isset($r['error'])) return $r['error'];
        $res = R::build($r['classe'], $r['period']);

        return response()->json([
            'classe' => ['id' => $r['classe']->id, 'name' => $r['classe']->name],
            'period' => ['id' => $r['period']->id, 'name' => $r['period']->name],
            'establishment' => $this->establishment(),
            'effectif' => $res['students']->count(),
            'stats' => $res['stats'],
            'rows' => $res['students']->map(fn ($s) => $this->row($res, $s))->values(),
            'teachers' => collect($res['mains'])->map(fn ($m) => [
                'discipline' => $m['name'], 'teacher' => $m['teacher']['name'], 'phone' => $m['teacher']['phone'],
            ])->values(),
            'warnings' => $res['warnings'],
        ], 200);
    }

    private function bulletin($res, $s, $classe, $period): array
    {
        $disc = [];
        $sumCoef = 0; $sumTotal = 0;
        foreach ($res['mains'] as $m) {
            $avg = $res['mainAvg'][$m['id']][$s->id] ?? null;
            $rk  = $res['subjRank'][$m['id']][$s->id] ?? null;
            $total = $avg !== null ? round($avg * $m['coef'], 2) : null;
            if ($avg !== null) { $sumCoef += $m['coef']; $sumTotal += $total; }
            $disc[] = ['id' => $m['id'], 'code' => $m['code'], 'name' => $m['name'], 'child' => false, 'average' => $avg, 'coef' => $m['coef'],
                'total' => $total, 'rank' => $rk['rank'] ?? null, 'tie' => $rk['tie'] ?? false,
                'appreciation' => R::appreciation($avg), 'teacher' => $m['teacher']['name']];
            foreach ($m['children'] as $c) {
                $cAvg = $res['childAvg'][$c['id']][$s->id] ?? null;
                $cRk  = $res['subjRank'][$c['id']][$s->id] ?? null;
                $disc[] = ['id' => $c['id'], 'code' => $c['code'], 'name' => $c['name'], 'child' => true,
                    'average' => $cAvg, 'coef' => $c['coef'], 'total' => $cAvg !== null ? round($cAvg * $c['coef'], 2) : null,
                    'rank' => $cRk['rank'] ?? null, 'tie' => $cRk['tie'] ?? false];
            }
        }
        $g = $res['general'][$s->id] ?? null;
        return [
            'student' => [
                'id' => $s->id, 'matricule' => $s->national_matricule ?: $s->matricule, 'last_name' => $s->last_name, 'first_name' => $s->first_name,
                'gender' => $s->gender, 'birth_date' => $s->birth_date, 'birth_place' => $s->birth_place, 'nationality' => $s->nationality,
                'photo' => $s->photo, 'repeater' => (bool) ($res['repeaters'][$s->id] ?? false),
                'assigned' => ($s->assignment_status ?? '') === 'affecte',
            ],
            'classe' => ['id' => $classe->id, 'name' => $classe->name, 'effectif' => $res['students']->count()],
            'period' => ['id' => $period->id, 'name' => $period->name],
            'disciplines' => $disc,
            'totals' => ['coef' => $sumCoef, 'total' => round($sumTotal, 2)],
            'general' => ['average' => $g, 'rank' => $res['rank'][$s->id]['rank'] ?? null, 'tie' => $res['rank'][$s->id]['tie'] ?? false,
                'appreciation' => R::appreciation($g)],
            'class_stats' => $res['stats'],
        ];
    }

    /** GET /report-cards/class/bulletins?classe_id=&period_id=  (impression par lot) */
    public function classBulletins(Request $request)
    {
        if ($d = $this->deny()) return $d;
        $r = $this->resolve($request);
        if (isset($r['error'])) return $r['error'];
        $res = R::build($r['classe'], $r['period']);

        return response()->json([
            'establishment' => $this->establishment(),
            'bulletins' => $res['students']->map(fn ($s) => $this->bulletin($res, $s, $r['classe'], $r['period']))->values(),
        ], 200);
    }

    /** GET /report-cards/student/{studentId}?classe_id=&period_id= */
    public function student(Request $request, string $studentId)
    {
        if ($d = $this->deny()) return $d;
        $r = $this->resolve($request);
        if (isset($r['error'])) return $r['error'];
        $res = R::build($r['classe'], $r['period']);
        $s = $res['students']->firstWhere('id', (int) $studentId);
        if (!$s) return response()->json(['message' => "Cet élève n'appartient pas à la classe."], 404);

        return response()->json(['establishment' => $this->establishment(), 'bulletin' => $this->bulletin($res, $s, $r['classe'], $r['period'])], 200);
    }
}