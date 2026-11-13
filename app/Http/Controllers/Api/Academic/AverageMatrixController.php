<?php

namespace App\Http\Controllers\Api\Academic;

use App\Http\Controllers\Controller;
use App\Models\Academic\Classe;
use App\Models\Academic\ClassroomSubjectTeacher;
use App\Models\Academic\Period;
use App\Models\Academic\Student;
use App\Models\Academic\StudentAcademicRecord;
use App\Models\Academic\Subject;
use App\Models\Academic\SubjectLevelSetting;
use App\Services\SubjectAverageCalculator as Calc;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Onglet « Moyenne période » (administration) : matrice des moyennes d'une classe pour une période.
 * Calculée à la volée à partir des notes (donc toujours à jour), moyenne générale pondérée par les
 * coefficients des matières de la classe (mêmes règles que ResultController).
 */
class AverageMatrixController extends Controller
{
    private function denyUnlessAdmin()
    {
        $u = Auth::user();
        $full = $u && ($u->roles()->where('slug', 'admin')->exists() || $u->can('grades.view_all'));
        return $full ? null : response()->json(['message' => "Cette section est réservée à l'administration."], 403);
    }

    /**
     * Matières principales de la classe (attributions) avec leurs sous-matières actives.
     * Coefficients : ceux définis dans le module « Matières & disciplines » pour le NIVEAU de la classe
     * (subject_level_settings). À défaut seulement : coefficient saisi sur l'attribution ; sinon 1 (signalé dans $warnings).
     */
    private function mainSubjects(Classe $classe, array &$warnings = [])
    {
        $atts = ClassroomSubjectTeacher::where('classe_id', $classe->id)->get()->unique('subject_id');
        $subjects = Subject::whereIn('id', $atts->pluck('subject_id'))->where('is_active', true)->get()->keyBy('id');
        $children = Subject::whereIn('parent_id', $subjects->keys())->where('is_active', true)->orderBy('name')->get()->groupBy('parent_id');
        $levelCoefs = SubjectLevelSetting::where('level_id', $classe->level_id)
            ->whereIn('subject_id', $subjects->keys())->pluck('coefficient', 'subject_id');

        return $atts->filter(fn ($a) => $subjects->has($a->subject_id))->map(function ($a) use ($subjects, $children, $levelCoefs, &$warnings) {
            $coef = $levelCoefs[$a->subject_id] ?? null;
            if ($coef === null) {
                $coef = $a->coefficient !== null ? (float) $a->coefficient : null;
                if ($coef === null) { $coef = 1.0; $warnings[] = $subjects[$a->subject_id]->name . ' : aucun coefficient défini (1 utilisé)'; }
                else { $warnings[] = $subjects[$a->subject_id]->name . " : coefficient du niveau non défini, celui de l'attribution ($coef) est utilisé"; }
            }
            return [
                'id'       => (int) $a->subject_id,
                'code'     => $subjects[$a->subject_id]->code,
                'name'     => $subjects[$a->subject_id]->name,
                'coef'     => (float) $coef,
                'children' => ($children[$a->subject_id] ?? collect())->map(fn ($c) => ['id' => (int) $c->id, 'code' => $c->code, 'name' => $c->name])->values()->all(),
            ];
        })->sortBy('name')->values();
    }

    /** GET /gradebook/matrix/filters?classe_id= */
    public function filters(Request $request)
    {
        if ($d = $this->denyUnlessAdmin()) return $d;
        $classe = Classe::find($request->classe_id);
        if (!$classe) return response()->json(['message' => 'Classe introuvable.'], 404);

        return response()->json($this->mainSubjects($classe)->map(fn ($m) => [
            'id' => $m['id'], 'label' => $m['name'] . ($m['code'] ? " ({$m['code']})" : ''), 'has_children' => count($m['children']) > 0,
        ])->values(), 200);
    }

    /** GET /gradebook/matrix?classe_id=&period_id=&filter=all|{subject_id} */
    public function matrix(Request $request)
    {
        if ($d = $this->denyUnlessAdmin()) return $d;

        $v = Validator::make($request->all(), ['classe_id' => 'required|integer', 'period_id' => 'required|integer', 'filter' => 'nullable']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $classe = Classe::find($request->classe_id);
        $period = Period::find($request->period_id);
        if (!$classe) return response()->json(['message' => 'Classe introuvable.'], 404);
        if (!$period) return response()->json(['message' => 'Période introuvable.'], 404);

        $warnings = [];
        $mains    = $this->mainSubjects($classe, $warnings);
        $students = Student::where('class_id', $classe->id)->whereHas('enrollments', fn ($q) => $q->where('status', '!=', 'cancelled'))->orderBy('last_name')->orderBy('first_name')->get();
        $ids      = $students->pluck('id')->all();

        // Moyennes de chaque matière principale (sous-matières incluses)
        $mainAvg = [];
        foreach ($mains as $m) {
            $mainAvg[$m['id']] = Calc::forSubjectTree((int) $classe->id, $m['id'], (int) $period->id);
        }

        // Coefficients des sous-matières (niveau de la classe)
        $childCoefs = SubjectLevelSetting::where('level_id', $classe->level_id)
            ->whereIn('subject_id', $mains->flatMap(fn ($m) => array_column($m['children'], 'id'))->all())->pluck('coefficient', 'subject_id');

        // Colonnes affichées selon le filtre
        $filter = $request->input('filter', 'all');
        $columns = []; $colAvg = [];
        $filterLabel = 'Toutes les matières';
        $selected = ($filter !== 'all' && $filter !== null && $filter !== '') ? $mains->firstWhere('id', (int) $filter) : null;

        if ($filter === 'all_sub') {
            $filterLabel = 'Avec sous-matières';
            foreach ($mains as $m) {
                foreach ($m['children'] as $c) {
                    $columns[] = ['id' => $c['id'], 'code' => $c['code'] ?: $c['name'], 'name' => $m['name'] . ' › ' . $c['name'], 'coef' => $childCoefs[$c['id']] ?? 1, 'sub' => true];
                    $colAvg[$c['id']] = array_map(fn ($r) => $r['average'], Calc::compute((int) $classe->id, $c['id'], (int) $period->id));
                }
                $columns[] = ['id' => $m['id'], 'code' => $m['code'] ?: $m['name'], 'name' => $m['name'] . (count($m['children']) ? ' (moyenne)' : ''), 'coef' => $m['coef'], 'sub' => false];
                $colAvg[$m['id']] = $mainAvg[$m['id']];
            }
        } elseif ($selected && count($selected['children'])) {
            $filterLabel = $selected['name'] . ' (avec sous-matières)';
            foreach ($selected['children'] as $c) {
                $columns[] = ['id' => $c['id'], 'code' => $c['code'] ?: $c['name'], 'name' => $c['name'], 'coef' => null];
                $colAvg[$c['id']] = array_map(fn ($r) => $r['average'], Calc::compute((int) $classe->id, $c['id'], (int) $period->id));
            }
            $columns[] = ['id' => $selected['id'], 'code' => $selected['code'] ?: $selected['name'], 'name' => $selected['name'] . ' (moyenne)', 'coef' => $selected['coef']];
            $colAvg[$selected['id']] = $mainAvg[$selected['id']];
        } elseif ($selected) {
            $filterLabel = $selected['name'];
            $columns[] = ['id' => $selected['id'], 'code' => $selected['code'] ?: $selected['name'], 'name' => $selected['name'], 'coef' => $selected['coef']];
            $colAvg[$selected['id']] = $mainAvg[$selected['id']];
        } else {
            foreach ($mains as $m) {
                $columns[] = ['id' => $m['id'], 'code' => $m['code'] ?: $m['name'], 'name' => $m['name'], 'coef' => $m['coef']];
                $colAvg[$m['id']] = $mainAvg[$m['id']];
            }
        }

        // Moyenne générale pondérée (toujours sur l'ensemble des matières principales)
        $general = [];
        foreach ($ids as $sid) {
            $pts = 0; $den = 0;
            foreach ($mains as $m) {
                if (isset($mainAvg[$m['id']][$sid])) { $pts += $mainAvg[$m['id']][$sid] * $m['coef']; $den += $m['coef']; }
            }
            if ($den > 0) $general[$sid] = round($pts / $den, 2);
        }

        // Rangs (ex æquo = même rang, rang suivant sauté)
        $sorted = $general; arsort($sorted);
        $rank = []; $tie = []; $pos = 0; $prev = null; $cur = 0;
        $counts = array_count_values(array_map(fn ($g) => (string) $g, $general));
        foreach ($sorted as $sid => $g) {
            $pos++;
            if ($prev === null || $g != $prev) $cur = $pos;
            $rank[$sid] = $cur; $tie[$sid] = $counts[(string) $g] > 1; $prev = $g;
        }

        $repeaters = StudentAcademicRecord::whereIn('student_id', $ids)->pluck('is_repeater', 'student_id');

        $rows = $students->map(function ($s) use ($colAvg, $columns, $general, $rank, $tie, $repeaters) {
            $avgs = [];
            foreach ($columns as $c) $avgs[$c['id']] = $colAvg[$c['id']][$s->id] ?? null;
            return [
                'id' => $s->id, 'matricule' => $s->national_matricule ?: $s->matricule,
                'last_name' => $s->last_name, 'first_name' => $s->first_name,
                'repeater' => (bool) ($repeaters[$s->id] ?? false),
                'averages' => $avgs, 'general' => $general[$s->id] ?? null,
                'rank' => $rank[$s->id] ?? null, 'tie' => $tie[$s->id] ?? false,
            ];
        })->values();

        $mean = function (array $vals) {
            $vals = array_filter($vals, fn ($x) => $x !== null);
            return $vals ? round(array_sum($vals) / count($vals), 2) : null;
        };
        $classAvg = [];
        foreach ($columns as $c) $classAvg[$c['id']] = $mean(array_map(fn ($sid) => $colAvg[$c['id']][$sid] ?? null, $ids));

        return response()->json([
            'classe' => ['id' => $classe->id, 'name' => $classe->name],
            'period' => ['id' => $period->id, 'name' => $period->name],
            'filter_label'  => $filterLabel,
            'columns'       => $columns,
            'rows'          => $rows,
            'class_averages' => $classAvg,
            'class_general' => $mean(array_values($general)),
            'warnings'      => array_values(array_unique($warnings)),
        ], 200);
    }
}