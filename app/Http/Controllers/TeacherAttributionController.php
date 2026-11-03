<?php

namespace App\Http\Controllers;

use App\Models\Academic\Classe;
use App\Models\Academic\ClassroomSubjectTeacher;
use App\Models\Academic\Level;
use App\Models\Academic\Subject;
use App\Models\Academic\SubjectLevelSetting;
use App\Models\Personel\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TeacherAttributionController extends Controller
{
    /** Requête de base : employés ACTIFS avec le poste « enseignant » (établissement courant). */
    private function teachersQuery()
    {
        return Employee::where('status', 'active')
            ->whereHas('position', fn ($q) => $q->where('slug', 'enseignant'));
    }

    /** Formate l'URL/chemin de photo tel que stocké ("/storage/..."). */
    private function teacherPayload(Employee $t): array
    {
        return [
            'id'         => $t->id,
            'first_name' => $t->first_name,
            'last_name'  => $t->last_name,
            'matricule'  => $t->matricule,
            'gender'     => $t->gender,
            'photo'      => $t->photo,
            'specialty'  => $t->specialty,
            'phone'      => $t->phone,
            'email'      => $t->email,
            'hire_date'  => $t->hire_date ? $t->hire_date->format('Y-m-d') : null,
        ];
    }

    /**
     * GET /academic/teachers
     */
    public function getTeachers()
    {
        $teachers = $this->teachersQuery()
            ->orderBy('last_name')->orderBy('first_name')->get()
            ->map(fn ($t) => $this->teacherPayload($t))->values();

        return response()->json($teachers, 200);
    }

    /**
     * GET /academic/teachers-overview
     * Vue « Planning enseignant » : enseignants + attributions + matières actives + indicateurs.
     * Les attributions comptées sont celles des classes de l'année consultée.
     */
    public function overview()
    {
        $teachers = $this->teachersQuery()
            ->orderBy('last_name')->orderBy('first_name')->get();

        $classeIds = Classe::pluck('id');

        $attributions = ClassroomSubjectTeacher::with(['subject', 'classe'])
            ->whereIn('employee_id', $teachers->pluck('id'))
            ->whereIn('classe_id', $classeIds)
            ->get()
            ->filter(fn ($a) => $a->subject && $a->classe)
            ->groupBy('employee_id');

        $rows = $teachers->map(function ($t) use ($attributions) {
            $atts = ($attributions->get($t->id) ?? collect())->map(fn ($a) => [
                'id'           => $a->id,
                'subject_id'   => $a->subject_id,
                'subject_name' => $a->subject->name,
                'subject_code' => $a->subject->code,
                'classe_id'    => $a->classe_id,
                'classe_name'  => $a->classe->name,
                'coefficient'  => $a->coefficient,
            ])->values();

            return $this->teacherPayload($t) + [
                'attributions_count' => $atts->count(),
                'is_attributed'      => $atts->isNotEmpty(),
                'attributions'       => $atts,
            ];
        })->values();

        $attributed = $rows->where('is_attributed', true)->count();

        return response()->json([
            'teachers' => $rows,
            'subjects' => Subject::where('is_active', true)->whereNull('parent_id')->orderBy('name')->get(['id', 'name', 'code']),
            'stats'    => [
                'total'        => $rows->count(),
                'attributed'   => $attributed,
                'unattributed' => $rows->count() - $attributed,
            ],
        ], 200);
    }

    /**
     * POST /academic/attributions/clear   { employee_ids: [..] }
     * Supprime toutes les attributions des enseignants choisis, pour l'année consultée.
     */
    public function clearAttributions(Request $request)
    {
        assert_writable_year();

        $data = $request->validate([
            'employee_ids'   => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer'],
        ]);

        // Seuls de vrais enseignants de cet établissement sont pris en compte
        $ids = $this->teachersQuery()->whereIn('id', $data['employee_ids'])->pluck('id');

        $deleted = DB::transaction(fn () =>
            ClassroomSubjectTeacher::whereIn('employee_id', $ids)
                ->whereIn('classe_id', Classe::pluck('id'))
                ->delete()
        );

        return response()->json([
            'status'  => 'success',
            'message' => $deleted . ' attribution(s) supprimée(s) pour ' . $ids->count() . ' enseignant(s).',
            'deleted' => $deleted,
        ], 200);
    }

    /** GET /academic/classes (utilisé par certaines pages) */
    public function getClasses()
    {
        return response()->json(Classe::orderBy('name', 'asc')->get(), 200);
    }

    /** Matières ACTIVES de l'établissement courant. */
    public function getSubjects()
    {
        return response()->json(Subject::where('is_active', true)->whereNull('parent_id')->orderBy('name', 'asc')->get(), 200);
    }

    /** GET /academic/teachers/{id} */
    public function showTeacher(string $id)
    {
        $teacher = $this->teachersQuery()->find($id);

        if (!$teacher) {
            return response()->json(['message' => 'Enseignant introuvable ou inactif.'], 404);
        }

        return response()->json($this->teacherPayload($teacher), 200);
    }

    /**
     * POST /academic/attributions
     * Le coefficient est optionnel : à défaut, celui défini pour le niveau de la classe est repris.
     */
    public function storeAttribution(Request $request)
    {
        assert_writable_year();

        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|integer',
            'classe_id'   => 'required|integer',
            'subject_id'  => 'required|integer',
            'coefficient' => 'nullable|integer|min:1|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Toutes les recherches passent par les modèles → filtrées sur l'établissement courant
        $teacher = $this->teachersQuery()->find($request->employee_id);
        if (!$teacher) {
            return response()->json(['errors' => ['employee_id' => ["Enseignant introuvable ou inactif."]]], 422);
        }

        $classe = Classe::find($request->classe_id);
        if (!$classe) {
            return response()->json(['errors' => ['classe_id' => ["Classe introuvable."]]], 422);
        }

        $subject = Subject::where('is_active', true)->whereNull('parent_id')->find($request->subject_id);
        if (!$subject) {
            return response()->json(['errors' => ['subject_id' => ["Matière introuvable ou inactive."]]], 422);
        }

        $levelSetting = SubjectLevelSetting::where('subject_id', $subject->id)
            ->where('level_id', $classe->level_id)->first();

        if ($levelSetting && $levelSetting->excluded_from_report) {
            return response()->json(['errors' => ['subject_id' => [
                "Cette matière est retirée du bulletin pour le niveau de cette classe : elle n'y est pas enseignée.",
            ]]], 422);
        }

        $coefficient = $request->coefficient;
        if ($coefficient === null || $coefficient === '') {
            $default = $levelSetting?->coefficient;
            if ($default === null || floor($default) != $default || $default < 1) {
                return response()->json(['errors' => ['coefficient' => [
                    "Aucun coefficient entier défini pour ce niveau : saisissez-le manuellement.",
                ]]], 422);
            }
            $coefficient = (int) $default;
        }

        $attribution = ClassroomSubjectTeacher::updateOrCreate(
            ['classe_id' => $classe->id, 'subject_id' => $subject->id],
            ['employee_id' => $teacher->id, 'coefficient' => $coefficient]
        );

        $attribution->load(['subject', 'classe.level']);

        return response()->json([
            'status'  => 'success',
            'message' => $attribution->wasRecentlyCreated
                ? 'Attribution enregistrée.'
                : 'Attribution mise à jour (l\'ancien enseignant de cette matière dans la classe est remplacé).',
            'data'    => $attribution,
        ], 200);
    }

    /** GET /academic/classes/{classeId}/subjects */
    public function getClassSubjects(string $classeId)
    {
        $classe = Classe::findOrFail($classeId);

        $attributions = ClassroomSubjectTeacher::with(['subject', 'teacher'])
            ->where('classe_id', $classe->id)
            ->get();

        return response()->json($attributions, 200);
    }

    /** GET /academic/teachers/{id}/attributions */
    public function getTeacherAttributions(string $id)
    {
        try {
            $teacher = $this->teachersQuery()->findOrFail((int) $id);

            $attributions = ClassroomSubjectTeacher::with(['subject', 'classe.level'])
                ->where('employee_id', $teacher->id)
                ->whereIn('classe_id', Classe::pluck('id'))
                ->get();

            return response()->json($attributions, 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Enseignant introuvable.'], 404);
        } catch (\Throwable $e) {
            Log::error('getTeacherAttributions : ' . $e->getMessage(), ['teacher_id' => $id]);
            return response()->json([
                'status'  => 'error',
                'message' => "Impossible de récupérer les attributions pour le moment.",
            ], 500);
        }
    }

    /** DELETE /academic/attributions/{id} */
    public function destroy(string $id)
    {
        assert_writable_year();

        $attribution = ClassroomSubjectTeacher::find($id);

        if (!$attribution || !Classe::where('id', $attribution->classe_id)->exists()) {
            return response()->json([
                'status'  => 'error',
                'message' => "Cette attribution n'existe pas.",
            ], 404);
        }

        $attribution->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Attribution supprimée avec succès.',
        ], 200);
    }

    /**
     * GET /academic/teachers/{id}/attributions-board
     * Niveaux → classes → matières, chacune avec son état d'attribution.
     */
    public function board(string $id)
    {
        $teacher = $this->teachersQuery()->find($id);
        if (!$teacher) {
            return response()->json(['message' => 'Enseignant introuvable ou inactif.'], 404);
        }

        $classes  = Classe::orderBy('name')->get();
        $subjects = Subject::where('is_active', true)->whereNull('parent_id')->orderBy('name')->get(['id', 'name', 'code']);

        $settings = SubjectLevelSetting::get()->keyBy(fn ($s) => $s->level_id . '-' . $s->subject_id);

        $atts = ClassroomSubjectTeacher::with('teacher')
            ->whereIn('classe_id', $classes->pluck('id'))
            ->get()
            ->keyBy(fn ($a) => $a->classe_id . '-' . $a->subject_id);

        $levels = Level::orderBy('id')->get(['id', 'name'])->map(function ($level) use ($classes, $subjects, $settings, $atts, $teacher) {
            $levelClasses = $classes->where('level_id', $level->id)->values()->map(function ($c) use ($subjects, $settings, $atts, $teacher, $level) {
                $rows = [];
                foreach ($subjects as $s) {
                    $st = $settings->get($level->id . '-' . $s->id);
                    if ($st && $st->excluded_from_report) continue;

                    $a = $atts->get($c->id . '-' . $s->id);
                    $rows[] = [
                        'subject_id'     => $s->id,
                        'name'           => $s->name,
                        'code'           => $s->code,
                        'attribution_id' => $a?->id,
                        'owner_id'       => $a?->employee_id,
                        'owner_name'     => $a && $a->teacher ? trim($a->teacher->last_name . ' ' . $a->teacher->first_name) : null,
                        'mine'           => $a && (int) $a->employee_id === (int) $teacher->id,
                    ];
                }
                return ['id' => $c->id, 'name' => $c->name, 'subjects' => $rows];
            })->filter(fn ($c) => count($c['subjects']) > 0)->values();

            return ['id' => $level->id, 'name' => $level->name, 'classes' => $levelClasses];
        })->filter(fn ($l) => $l['classes']->isNotEmpty())->values();

        return response()->json([
            'teacher' => $this->teacherPayload($teacher),
            'levels'  => $levels,
        ], 200);
    }

    /**
     * POST /academic/teachers/{id}/attributions-sync
     * { add: [{classe_id, subject_id}], remove: [{classe_id, subject_id}] }
     */
    public function syncAttributions(Request $request, string $id)
    {
        assert_writable_year();

        $teacher = $this->teachersQuery()->find($id);
        if (!$teacher) {
            return response()->json(['message' => 'Enseignant introuvable ou inactif.'], 404);
        }

        $data = $request->validate([
            'add'                 => ['nullable', 'array'],
            'add.*.classe_id'     => ['required', 'integer'],
            'add.*.subject_id'    => ['required', 'integer'],
            'remove'              => ['nullable', 'array'],
            'remove.*.classe_id'  => ['required', 'integer'],
            'remove.*.subject_id' => ['required', 'integer'],
        ]);

        $add    = $data['add'] ?? [];
        $remove = $data['remove'] ?? [];

        if (!empty($remove) && !$request->user()->can('teacher_attributions.delete')) {
            return response()->json(['message' => "Vous n'avez pas l'autorisation de retirer des attributions."], 403);
        }

        $classes  = Classe::pluck('level_id', 'id');           // id => level_id (établissement/année courants)
        $subjects = Subject::where('is_active', true)->whereNull('parent_id')->pluck('id')->flip();

        $added = 0; $removed = 0; $skipped = [];

        DB::transaction(function () use ($add, $remove, $teacher, $classes, $subjects, &$added, &$removed, &$skipped) {
            foreach ($remove as $r) {
                if (!$classes->has($r['classe_id'])) continue;
                $removed += ClassroomSubjectTeacher::where('employee_id', $teacher->id)
                    ->where('classe_id', $r['classe_id'])
                    ->where('subject_id', $r['subject_id'])
                    ->delete();
            }

            foreach ($add as $a) {
                $cid = (int) $a['classe_id']; $sid = (int) $a['subject_id'];

                if (!$classes->has($cid) || !$subjects->has($sid)) { $skipped[] = [$cid, $sid, 'invalide']; continue; }

                $st = SubjectLevelSetting::where('subject_id', $sid)->where('level_id', $classes[$cid])->first();
                if ($st && $st->excluded_from_report) { $skipped[] = [$cid, $sid, 'non enseignée']; continue; }

                $existing = ClassroomSubjectTeacher::where('classe_id', $cid)->where('subject_id', $sid)->first();
                if ($existing) {
                    if ((int) $existing->employee_id !== (int) $teacher->id) { $skipped[] = [$cid, $sid, 'déjà attribuée']; }
                    continue;
                }

                $coef = $st?->coefficient;
                $coef = ($coef !== null && floor($coef) == $coef && $coef >= 1 && $coef <= 20) ? (int) $coef : 1;

                ClassroomSubjectTeacher::create([
                    'classe_id' => $cid, 'subject_id' => $sid,
                    'employee_id' => $teacher->id, 'coefficient' => $coef,
                ]);
                $added++;
            }
        });

        $msg = "$added ajout(s), $removed retrait(s) enregistré(s).";
        if ($skipped) $msg .= ' ' . count($skipped) . ' matière(s) ignorée(s) (déjà prises par un autre enseignant ou non enseignées).';

        return response()->json(['status' => 'success', 'message' => $msg, 'added' => $added, 'removed' => $removed, 'skipped' => count($skipped)], 200);
    }
}