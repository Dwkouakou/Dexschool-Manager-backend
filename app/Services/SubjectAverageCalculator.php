<?php

namespace App\Services;

use App\Models\Academic\Classe;
use App\Models\Academic\Evaluation;
use App\Models\Academic\Grade;
use App\Models\Academic\GradeBonus;
use App\Models\Academic\Subject;
use App\Models\Academic\SubjectAverage;
use App\Models\Academic\SubjectLevelSetting;

/**
 * Moyenne d'une matière pour une période :
 *   chaque note est ramenée sur 20 (note × 20 / barème) puis pondérée :
 *     interro sur 10 → ×2 puis coefficient 0,5 | interro sur 20 → coefficient 1 | devoir → coefficient 2
 *   moyenne = (Σ note/20 × coefficient + bonus points) / Σ coefficients + bonus moyenne   (bornée à 0–20)
 */
class SubjectAverageCalculator
{
    public static function weightFor(string $type, $maxScore): float
    {
        return match (true) {
            $type === 'interrogation' && (float) $maxScore <= 10 => 0.5,
            $type === 'interrogation'                            => 1.0,
            default                                              => 2.0,   // devoir, examen
        };
    }

    /** @return array<int, array{average: ?float, points: float, weights: float, notes: int}> indexé par student_id */
    public static function compute(int $classeId, int $subjectId, int $periodId, ?array $studentIds = null): array
    {
        $evals = Evaluation::where('classe_id', $classeId)->where('subject_id', $subjectId)->where('period_id', $periodId)->get()->keyBy('id');

        $out = [];
        $grades = $evals->isEmpty() ? collect() : Grade::whereIn('evaluation_id', $evals->keys())
            ->whereNotNull('score')->where('is_absent', false)
            ->when($studentIds, fn ($q) => $q->whereIn('student_id', $studentIds))->get();

        foreach ($grades as $g) {
            $e = $evals[$g->evaluation_id];
            $max = (float) $e->max_score ?: 20;
            $w   = self::weightFor($e->type, $max);
            $out[$g->student_id] ??= ['average' => null, 'points' => 0.0, 'weights' => 0.0, 'notes' => 0];
            $out[$g->student_id]['points']  += ((float) $g->score * 20 / $max) * $w;
            $out[$g->student_id]['weights'] += $w;
            $out[$g->student_id]['notes']++;
        }

        $bonuses = GradeBonus::where('classe_id', $classeId)->where('subject_id', $subjectId)->where('period_id', $periodId)
            ->when($studentIds, fn ($q) => $q->whereIn('student_id', $studentIds))->get()->keyBy('student_id');

        foreach ($out as $sid => &$row) {
            $b   = $bonuses->get($sid);
            $avg = ($row['points'] + ($b->bonus_points ?? 0)) / $row['weights'] + ($b->bonus_average ?? 0);
            $row['average'] = round(max(0, min(20, $avg)), 2);
        }

        return $out;
    }

    /** Moyenne de la matière en tenant compte des sous-matières (moyenne pondérée par leurs coefficients de niveau). */
    public static function forSubjectTree(int $classeId, int $subjectId, int $periodId): array
    {
        $children = Subject::where('parent_id', $subjectId)->where('is_active', true)->pluck('id');
        if ($children->isEmpty()) {
            return array_map(fn ($r) => $r['average'], self::compute($classeId, $subjectId, $periodId));
        }

        $levelId = Classe::where('id', $classeId)->value('level_id');
        $coefs   = SubjectLevelSetting::where('level_id', $levelId)->whereIn('subject_id', $children)->pluck('coefficient', 'subject_id');

        $sum = []; $den = [];
        foreach ($children as $cid) {
            $c = (float) ($coefs[$cid] ?? 1) ?: 1;
            foreach (self::compute($classeId, $cid, $periodId) as $sid => $r) {
                $sum[$sid] = ($sum[$sid] ?? 0) + $r['average'] * $c;
                $den[$sid] = ($den[$sid] ?? 0) + $c;
            }
        }
        $res = [];
        foreach ($sum as $sid => $v) $res[$sid] = round($v / $den[$sid], 2);
        return $res;
    }

    /** Écrit/supprime les lignes subject_averages (utilisées par moyennes de période et bulletins). */
    public static function sync(int $classeId, int $subjectId, int $periodId, ?array $studentIds = null): void
    {
        $computed = self::compute($classeId, $subjectId, $periodId, $studentIds);

        foreach ($computed as $sid => $row) {
            SubjectAverage::updateOrCreate(
                ['student_id' => $sid, 'subject_id' => $subjectId, 'period_id' => $periodId],
                ['classe_id' => $classeId, 'average' => $row['average']]
            );
        }

        $toClear = SubjectAverage::where('classe_id', $classeId)->where('subject_id', $subjectId)->where('period_id', $periodId)
            ->when($studentIds, fn ($q) => $q->whereIn('student_id', $studentIds))
            ->whereNotIn('student_id', array_keys($computed));
        $toClear->delete();
    }

    /** Synchronise la matière ET, s'il s'agit d'une sous-matière, la moyenne de sa matière principale. */
    public static function syncWithParent(int $classeId, int $subjectId, int $periodId, ?array $studentIds = null): void
    {
        self::sync($classeId, $subjectId, $periodId, $studentIds);

        $parentId = Subject::where('id', $subjectId)->value('parent_id');
        if (!$parentId) return;

        $tree = self::forSubjectTree($classeId, $parentId, $periodId);
        $tree = $studentIds ? array_intersect_key($tree, array_flip($studentIds)) : $tree;

        foreach ($tree as $sid => $avg) {
            SubjectAverage::updateOrCreate(
                ['student_id' => $sid, 'subject_id' => $parentId, 'period_id' => $periodId],
                ['classe_id' => $classeId, 'average' => $avg]
            );
        }

        SubjectAverage::where('classe_id', $classeId)->where('subject_id', $parentId)->where('period_id', $periodId)
            ->when($studentIds, fn ($q) => $q->whereIn('student_id', $studentIds))
            ->whereNotIn('student_id', array_keys($tree))->delete();
    }
}