<?php

namespace App\Http\Controllers\Api\Academic;

use App\Http\Controllers\Controller;
use App\Models\Academic\Classe;
use App\Models\Academic\ClassroomSubjectTeacher;
use App\Models\Academic\Evaluation;
use App\Models\Academic\Grade;
use App\Models\Academic\GradeBonus;
use App\Models\Academic\Period;
use App\Models\Academic\Subject;
use App\Models\Personel\Employee;
use App\Models\Academic\Student;
use App\Services\SubjectAverageCalculator as Calc;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Saisie et consultation des notes (onglet « Notes » de la Gestion des notes).
 * Une « note » saisie à la fois pour plusieurs élèves = une Evaluation (colonne) + des Grade (cases).
 */
class GradeBookController extends Controller
{
    /** kind → [type, barème, libellé] */
    private const KINDS = [
        'interro10' => ['interrogation', 10, 'Interro sur 10'],
        'interro20' => ['interrogation', 20, 'Interro sur 20'],
        'devoir'    => ['devoir',        20, 'Devoir'],
    ];

    private function fail(string $msg, int $code = 422)
    {
        return response()->json(['status' => 'error', 'message' => $msg, 'errors' => ['_' => [$msg]]], $code);
    }

    /** Matières « feuilles » notables d'une classe : matières attribuées sans sous-matière + les sous-matières des autres. */
    private function leafSubjects(Classe $classe)
    {
        $atts  = ClassroomSubjectTeacher::where('classe_id', $classe->id)->get()->keyBy('subject_id');
        $mains = Subject::whereIn('id', $atts->keys())->where('is_active', true)->get();
        $kids  = Subject::whereIn('parent_id', $mains->pluck('id'))->where('is_active', true)->get()->groupBy('parent_id');
        $teachers = Employee::whereIn('id', $atts->pluck('employee_id'))->get()->keyBy('id');

        $rows = collect();
        foreach ($mains->sortBy('name') as $m) {
            $teacher = $teachers->get($atts[$m->id]->employee_id);
            $tName   = $teacher ? trim($teacher->last_name . ' ' . $teacher->first_name) : null;
            $children = $kids->get($m->id, collect())->sortBy('name');

            if ($children->isEmpty()) {
                $rows->push(['id' => $m->id, 'label' => $m->name, 'name' => $m->name, 'code' => $m->code,
                    'employee_id' => $atts[$m->id]->employee_id, 'teacher_name' => $tName]);
            } else {
                foreach ($children as $c) {
                    $rows->push(['id' => $c->id, 'label' => $m->name . ' › ' . $c->name, 'name' => $c->name, 'code' => $c->code,
                        'employee_id' => $atts[$m->id]->employee_id, 'teacher_name' => $tName]);
                }
            }
        }
        return $rows->values();
    }

    /** Charge classe + période + matière (tous filtrés par le scope établissement/année) ou renvoie une erreur. */
    private function resolve(Request $request)
    {
        $v = Validator::make($request->all(), [
            'classe_id'  => 'required|integer',
            'period_id'  => 'required|integer',
            'subject_id' => 'required|integer',
        ]);
        if ($v->fails()) return ['error' => response()->json(['errors' => $v->errors()], 422)];

        $classe = Classe::with('level')->find($request->classe_id);
        $period = Period::find($request->period_id);
        if (!$classe) return ['error' => $this->fail('Classe introuvable.', 404)];
        if (!$period) return ['error' => $this->fail('Période introuvable.', 404)];

        $subject = $this->leafSubjects($classe)->firstWhere('id', (int) $request->subject_id);
        if (!$subject) return ['error' => $this->fail("Cette matière n'est pas attribuée à cette classe.")];

        return compact('classe', 'period', 'subject');
    }

    private function studentsOf(Classe $classe)
    {
        return Student::where('class_id', $classe->id)->orderBy('last_name')->orderBy('first_name')->get();
    }

    /** GET /gradebook/context */
    public function context()
    {
        $periods = Period::orderBy('id')->get(['id', 'name', 'code', 'is_active']);

        return response()->json([
            'periods'          => $periods,
            'active_period_id' => $periods->firstWhere('is_active', true)?->id,
            'classes'          => Classe::with('level')->orderBy('name')->get()->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'level_name' => $c->level->name ?? null,
            ])->values(),
        ], 200);
    }

    /** GET /gradebook/subjects?classe_id= */
    public function subjects(Request $request)
    {
        $classe = Classe::find($request->classe_id);
        if (!$classe) return $this->fail('Classe introuvable.', 404);

        return response()->json($this->leafSubjects($classe), 200);
    }

    /** GET /gradebook/sheet?classe_id=&period_id=&subject_id= */
    public function sheet(Request $request)
    {
        $r = $this->resolve($request);
        if (isset($r['error'])) return $r['error'];
        ['classe' => $classe, 'period' => $period, 'subject' => $subject] = $r;

        $students = $this->studentsOf($classe);
        $evals    = Evaluation::where('classe_id', $classe->id)->where('subject_id', $subject['id'])->where('period_id', $period->id)
            ->orderBy('date')->orderBy('id')->get();

        $grades = $evals->isEmpty() ? collect() : Grade::whereIn('evaluation_id', $evals->pluck('id'))->get(['id', 'evaluation_id', 'student_id', 'score', 'is_absent']);
        $bonus  = GradeBonus::where('classe_id', $classe->id)->where('subject_id', $subject['id'])->where('period_id', $period->id)->get()->keyBy('student_id');
        $calc   = Calc::compute($classe->id, $subject['id'], $period->id);

        return response()->json([
            'classe'  => ['id' => $classe->id, 'name' => $classe->name],
            'period'  => ['id' => $period->id, 'name' => $period->name],
            'subject' => $subject,
            'evaluations' => $evals->map(fn ($e) => [
                'id' => $e->id, 'title' => $e->title, 'type' => $e->type, 'max_score' => (float) $e->max_score,
                'weight' => Calc::weightFor($e->type, $e->max_score), 'date' => $e->date,
            ])->values(),
            'grades'  => $grades->map(fn ($g) => [
                'evaluation_id' => $g->evaluation_id, 'student_id' => $g->student_id, 'score' => $g->score,
            ])->values(),
            'students' => $students->map(fn ($s) => [
                'id'            => $s->id,
                'matricule'     => $s->national_matricule ?: $s->matricule,
                'last_name'     => $s->last_name,
                'first_name'    => $s->first_name,
                'bonus_average' => (float) ($bonus[$s->id]->bonus_average ?? 0),
                'bonus_points'  => (float) ($bonus[$s->id]->bonus_points ?? 0),
                'average'       => $calc[$s->id]['average'] ?? null,
                'notes_count'   => $calc[$s->id]['notes'] ?? 0,
            ])->values(),
        ], 200);
    }

    /**
     * POST /gradebook/notes
     * { classe_id, period_id, subject_id, kind: interro10|interro20|devoir, grades: [{student_id, score|null}] }
     */
    public function storeNotes(Request $request)
    {
        assert_writable_year();

        $r = $this->resolve($request);
        if (isset($r['error'])) return $r['error'];
        ['classe' => $classe, 'period' => $period, 'subject' => $subject] = $r;

        $v = Validator::make($request->all(), [
            'kind'                => 'required|in:' . implode(',', array_keys(self::KINDS)),
            'grades'              => 'required|array|min:1',
            'grades.*.student_id' => 'required|integer',
            'grades.*.score'      => 'nullable|numeric|min:0',
        ], ['kind.in' => 'Poids de note invalide.']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        [$type, $max, $label] = self::KINDS[$request->kind];

        $entries = collect($request->grades)->filter(fn ($g) => $g['score'] !== null && $g['score'] !== '')->values();
        if ($entries->isEmpty()) return $this->fail('Saisissez au moins une note.');

        $studentIds = $this->studentsOf($classe)->pluck('id');
        foreach ($entries as $g) {
            if (!$studentIds->contains((int) $g['student_id'])) return $this->fail("Un élève n'appartient pas à cette classe.");
            if ((float) $g['score'] > $max) return $this->fail("Les notes doivent être comprises entre 0 et $max.");
        }

        if (!$subject['employee_id']) return $this->fail("Attribuez d'abord cette matière à un enseignant.");

        $eval = DB::transaction(function () use ($request, $classe, $period, $subject, $type, $max, $label, $entries) {
            $n = Evaluation::where('classe_id', $classe->id)->where('subject_id', $subject['id'])->where('period_id', $period->id)
                ->where('type', $type)->where('max_score', $max)->count() + 1;
            while (Evaluation::where('classe_id', $classe->id)->where('subject_id', $subject['id'])->where('period_id', $period->id)
                ->where('title', "$label n°$n")->exists()) { $n++; }

            $eval = Evaluation::create([
                'title' => "$label n°$n", 'type' => $type, 'date' => now()->toDateString(),
                'classe_id' => $classe->id, 'subject_id' => $subject['id'], 'employee_id' => $subject['employee_id'],
                'period_id' => $period->id, 'max_score' => $max,
            ]);

            foreach ($entries as $g) {
                Grade::create(['evaluation_id' => $eval->id, 'student_id' => (int) $g['student_id'], 'score' => $g['score'], 'is_absent' => false]);
            }
            return $eval;
        });

        Calc::syncWithParent($classe->id, $subject['id'], $period->id);

        return response()->json([
            'status' => 'success', 'message' => "« {$eval->title} » enregistrée pour {$entries->count()} élève(s).", 'data' => $eval,
        ], 201);
    }

    /** Contrôle commun à la modification / suppression d'une note d'élève. */
    private function gradeContext(string $evaluationId, string $studentId)
    {
        $eval = Evaluation::find($evaluationId);
        if (!$eval || !Classe::where('id', $eval->classe_id)->exists()) return ['error' => $this->fail('Note introuvable.', 404)];
        if (!Period::where('id', $eval->period_id)->exists())           return ['error' => $this->fail('Période introuvable.', 404)];
        if (!Student::where('id', $studentId)->where('class_id', $eval->classe_id)->exists()) {
            return ['error' => $this->fail("Cet élève n'appartient pas à la classe.")];
        }
        return ['eval' => $eval];
    }

    /** PUT /gradebook/evaluations/{evaluationId}/students/{studentId}  { score } */
    public function updateGrade(Request $request, string $evaluationId, string $studentId)
    {
        assert_writable_year();
        $c = $this->gradeContext($evaluationId, $studentId);
        if (isset($c['error'])) return $c['error'];
        $eval = $c['eval'];

        $v = Validator::make($request->all(), ['score' => 'required|numeric|min:0|max:' . $eval->max_score], [
            'score.max' => "La note doit être comprise entre 0 et {$eval->max_score}.",
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        Grade::updateOrCreate(
            ['evaluation_id' => $eval->id, 'student_id' => (int) $studentId],
            ['score' => $request->score, 'is_absent' => false]
        );
        Calc::syncWithParent($eval->classe_id, $eval->subject_id, $eval->period_id, [(int) $studentId]);

        return response()->json(['status' => 'success', 'message' => 'Note modifiée.'], 200);
    }

    /** DELETE /gradebook/evaluations/{evaluationId}/students/{studentId} */
    public function deleteGrade(string $evaluationId, string $studentId)
    {
        assert_writable_year();
        $c = $this->gradeContext($evaluationId, $studentId);
        if (isset($c['error'])) return $c['error'];
        $eval = $c['eval'];

        Grade::where('evaluation_id', $eval->id)->where('student_id', $studentId)->delete();

        // Colonne devenue vide : on supprime aussi l'évaluation
        if (!Grade::where('evaluation_id', $eval->id)->exists()) $eval->delete();

        Calc::syncWithParent($eval->classe_id, $eval->subject_id, $eval->period_id, [(int) $studentId]);

        return response()->json(['status' => 'success', 'message' => 'Note supprimée.'], 200);
    }

    /**
     * POST /gradebook/bonus
     * { classe_id, period_id, subject_id, kind: average|points, value, student_ids: [] }
     */
    public function saveBonus(Request $request)
    {
        assert_writable_year();

        $r = $this->resolve($request);
        if (isset($r['error'])) return $r['error'];
        ['classe' => $classe, 'period' => $period, 'subject' => $subject] = $r;

        $v = Validator::make($request->all(), [
            'kind'          => 'required|in:average,points',
            'value'         => 'required|numeric|min:0|max:' . ($request->kind === 'average' ? 20 : 99),
            'student_ids'   => 'required|array|min:1',
            'student_ids.*' => 'integer',
        ], ['student_ids.required' => 'Sélectionnez au moins un élève.', 'student_ids.min' => 'Sélectionnez au moins un élève.']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $ids = $this->studentsOf($classe)->pluck('id')->intersect($request->student_ids)->values();
        $col = $request->kind === 'average' ? 'bonus_average' : 'bonus_points';

        DB::transaction(function () use ($ids, $classe, $subject, $period, $col, $request) {
            foreach ($ids as $sid) {
                GradeBonus::updateOrCreate(
                    ['student_id' => $sid, 'subject_id' => $subject['id'], 'period_id' => $period->id],
                    ['classe_id' => $classe->id, $col => $request->value]
                );
            }
        });

        Calc::syncWithParent($classe->id, $subject['id'], $period->id, $ids->all());

        return response()->json(['status' => 'success', 'message' => "Bonus appliqué à {$ids->count()} élève(s)."], 200);
    }
}