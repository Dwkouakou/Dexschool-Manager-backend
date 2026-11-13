<?php

namespace App\Services;

use App\Models\Academic\Classe;
use App\Models\Academic\ClassroomSubjectTeacher;
use App\Models\Academic\Period;
use App\Models\Academic\Student;
use App\Models\Academic\StudentAcademicRecord;
use App\Models\Academic\Subject;
use App\Models\Academic\SubjectLevelSetting;
use App\Models\Personel\Employee;

/**
 * Résultats complets d'une classe pour une période (source unique pour les bulletins).
 *  - moyennes par matière (sous-matières incluses) via SubjectAverageCalculator
 *  - coefficients : ceux du module « Matières & disciplines » (niveau de la classe) ; à défaut celui de l'attribution ; sinon 1 (signalé)
 *  - moyenne générale pondérée, rangs (ex æquo), statistiques de classe
 */
class ClassResultsService
{
    /** Bilans disponibles (clé stockée dans subjects.bilan => libellé), dans l'ordre d'affichage. */
    public const BILANS = ['lettres' => 'Bilan Lettres', 'sciences' => 'Bilan Sciences', 'autres' => 'Bilan Autres'];

    /** Appréciation selon la moyenne. $scale = barème de la valeur (20 par défaut, 10 pour une note sur 10). */
    public static function appreciation(?float $avg, int $scale = 20): string
    {
        if ($avg === null) return '';
        $v = $avg * 20 / max(1, $scale);
        return match (true) {
            $v >= 18  => 'Excellent',
            $v >= 16  => 'Très bien',
            $v >= 14  => 'Bien',
            $v >= 12  => 'Assez bien',
            $v >= 10  => 'Passable',
            $v >= 8.5 => 'Insuffisant',
            $v >= 5   => 'Médiocre',
            default   => 'Nul',
        };
    }

    /** Rangs avec ex æquo : [id => [rank, tie]] (valeurs arrondies à 2 décimales). */
    public static function rank(array $values): array
    {
        arsort($values);
        $counts = array_count_values(array_map(fn ($v) => (string) $v, $values));
        $out = []; $pos = 0; $cur = 0; $prev = null;
        foreach ($values as $id => $v) {
            $pos++;
            if ($prev === null || $v != $prev) $cur = $pos;
            $out[$id] = ['rank' => $cur, 'tie' => $counts[(string) $v] > 1];
            $prev = $v;
        }
        return $out;
    }

    private static function teacherLabel(?Employee $e): array
    {
        if (!$e) return ['name' => '', 'phone' => ''];
        $civ = str_starts_with(strtoupper((string) $e->gender), 'M') ? 'M.' : 'MME';
        return ['name' => trim($civ . ' ' . mb_strtoupper($e->last_name) . ' ' . $e->first_name), 'phone' => (string) $e->phone];
    }

    public static function build(Classe $classe, Period $period): array
    {
        $warnings = [];
        $atts = ClassroomSubjectTeacher::where('classe_id', $classe->id)->get()->unique('subject_id');
        $subjects = Subject::whereIn('id', $atts->pluck('subject_id'))->where('is_active', true)->get()->keyBy('id');
        $children = Subject::whereIn('parent_id', $subjects->keys())->where('is_active', true)->orderBy('name')->get()->groupBy('parent_id');
        $childIds = $children->flatten()->pluck('id');
        $levelCoefs = SubjectLevelSetting::where('level_id', $classe->level_id)
            ->whereIn('subject_id', $subjects->keys()->merge($childIds))->pluck('coefficient', 'subject_id');
        $employees = Employee::whereIn('id', $atts->pluck('employee_id')->filter())->get()->keyBy('id');

        $mains = $atts->filter(fn ($a) => $subjects->has($a->subject_id))->map(function ($a) use ($subjects, $children, $levelCoefs, $employees, &$warnings) {
            $s = $subjects[$a->subject_id];
            $coef = $levelCoefs[$a->subject_id] ?? null;
            if ($coef === null) {
                if ($a->coefficient !== null) { $coef = (float) $a->coefficient; $warnings[] = "{$s->name} : coefficient du niveau non défini, celui de l'attribution ($coef) est utilisé"; }
                else { $coef = 1.0; $warnings[] = "{$s->name} : aucun coefficient défini (1 utilisé)"; }
            }
            return [
                'id' => (int) $s->id, 'code' => $s->code, 'name' => $s->name, 'coef' => (float) $coef,
                'bilan' => in_array($s->bilan, array_keys(self::BILANS), true) ? $s->bilan : null,
                'teacher' => self::teacherLabel($employees[$a->employee_id] ?? null),
                'children' => ($children[$s->id] ?? collect())->map(fn ($c) => [
                    'id' => (int) $c->id, 'code' => $c->code, 'name' => $c->name, 'coef' => (float) ($levelCoefs[$c->id] ?? 1),
                ])->values()->all(),
            ];
        })->sortBy('name')->values()->all();

        // Regroupement par bilan (lettres, sciences, autres) ; les matières sans bilan viennent à la fin
        $order = array_keys(self::BILANS);
        usort($mains, function ($a, $b) use ($order) {
            $ia = $a['bilan'] === null ? 99 : array_search($a['bilan'], $order);
            $ib = $b['bilan'] === null ? 99 : array_search($b['bilan'], $order);
            return $ia <=> $ib ?: strcmp($a['name'], $b['name']);
        });

        $students = Student::where('class_id', $classe->id)->whereHas('enrollments', fn ($q) => $q->where('status', '!=', 'cancelled'))->orderBy('last_name')->orderBy('first_name')->get();
        $ids = $students->pluck('id')->all();

        $mainAvg = []; $childAvg = []; $subjRank = [];
        foreach ($mains as $m) {
            $mainAvg[$m['id']] = SubjectAverageCalculator::forSubjectTree((int) $classe->id, $m['id'], (int) $period->id);
            $subjRank[$m['id']] = self::rank($mainAvg[$m['id']]);
            foreach ($m['children'] as $c) {
                $childAvg[$c['id']] = array_map(fn ($r) => $r['average'], SubjectAverageCalculator::compute((int) $classe->id, $c['id'], (int) $period->id));
                $subjRank[$c['id']] = self::rank($childAvg[$c['id']]);
            }
        }

        // Bilans : moyenne = Σ(moy × coef) / Σ coef des matières du bilan ; rang dans la classe
        $bilans = [];
        foreach (self::BILANS as $key => $label) {
            $group = array_values(array_filter($mains, fn ($m) => $m['bilan'] === $key));
            if (!$group) continue;
            $avgs = []; $sums = [];
            foreach ($ids as $sid) {
                $pts = 0; $den = 0;
                foreach ($group as $m) {
                    if (isset($mainAvg[$m['id']][$sid])) { $pts += $mainAvg[$m['id']][$sid] * $m['coef']; $den += $m['coef']; }
                }
                if ($den > 0) { $avgs[$sid] = round($pts / $den, 2); $sums[$sid] = ['coef' => $den, 'total' => round($pts, 2)]; }
            }
            $bilans[$key] = ['label' => $label, 'avg' => $avgs, 'sums' => $sums, 'rank' => self::rank($avgs), 'last' => end($group)['id']];
        }

        $general = [];
        foreach ($ids as $sid) {
            $pts = 0; $den = 0;
            foreach ($mains as $m) {
                if (isset($mainAvg[$m['id']][$sid])) { $pts += $mainAvg[$m['id']][$sid] * $m['coef']; $den += $m['coef']; }
            }
            if ($den > 0) $general[$sid] = round($pts / $den, 2);
        }
        $rank = self::rank($general);

        $stats = ['mean' => null, 'min' => null, 'max' => null];
        if ($general) {
            $stats = ['mean' => round(array_sum($general) / count($general), 2), 'min' => min($general), 'max' => max($general)];
        }

        return [
            'mains' => $mains, 'students' => $students,
            'mainAvg' => $mainAvg, 'childAvg' => $childAvg, 'subjRank' => $subjRank,
            'bilans' => $bilans, 'general' => $general, 'rank' => $rank, 'stats' => $stats,
            'repeaters' => StudentAcademicRecord::whereIn('student_id', $ids)->pluck('is_repeater', 'student_id'),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }
}