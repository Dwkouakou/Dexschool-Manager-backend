<?php

namespace App\Http\Controllers;

use App\Models\Academic\Level;
use App\Models\Academic\Subject;
use App\Models\Academic\SubjectLevelSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubjectLevelSettingController extends Controller
{
    /** GET /academic/subject-settings/levels — niveaux de l'établissement courant. */
    public function levels()
    {
        $levels = Level::with('cycle')->orderBy('order')->get()->map(fn ($l) => [
            'id'         => $l->id,
            'name'       => $l->name,
            'cycle_name' => $l->cycle->name ?? null,
        ])->values();

        return response()->json($levels, 200);
    }

    /**
     * GET /academic/subject-settings?level_id=
     * Matières actives + coefficient et statut bulletin pour ce niveau.
     */
    public function show(Request $request)
    {
        $request->validate(['level_id' => ['required', 'integer']]);
        $level = Level::with('cycle')->findOrFail($request->level_id);

        $settings = SubjectLevelSetting::where('level_id', $level->id)->get()->keyBy('subject_id');

        $all = Subject::where('is_active', true)->orderBy('name')->get();
        // Ordre : chaque matière principale suivie de ses sous-matières
        $ordered = $all->whereNull('parent_id')->flatMap(
            fn ($m) => collect([$m])->merge($all->where('parent_id', $m->id))
        );

        $subjects = $ordered->map(function ($s) use ($settings) {
            $st = $settings->get($s->id);
            return [
                'id'                   => $s->id,
                'parent_id'            => $s->parent_id,
                'name'                 => $s->name,
                'code'                 => $s->code,
                'bilan'                => $s->bilan,
                'coefficient'          => $st?->coefficient,
                'excluded_from_report' => (bool) ($st?->excluded_from_report ?? false),
            ];
        })->values();

        return response()->json([
            'level'    => ['id' => $level->id, 'name' => $level->name, 'cycle_name' => $level->cycle->name ?? null],
            'subjects' => $subjects,
        ], 200);
    }

    /** Vérifie que niveau et matières appartiennent à l'établissement courant. */
    private function guard(int $levelId, array $subjectIds): void
    {
        Level::findOrFail($levelId);
        $valid = Subject::whereIn('id', $subjectIds)->pluck('id')->all();
        abort_if(count(array_unique($subjectIds)) !== count($valid), 422, "Matière invalide.");
    }

    /**
     * PUT /academic/subject-settings/coefficients
     * { level_id, items: [{ subject_id, coefficient|null }] }
     */
    public function saveCoefficients(Request $request)
    {
        $data = $request->validate([
            'level_id'              => ['required', 'integer'],
            'items'                 => ['required', 'array', 'min:1'],
            'items.*.subject_id'    => ['required', 'integer'],
            'items.*.coefficient'   => ['nullable', 'numeric', 'min:0', 'max:99'],
        ]);

        $this->guard($data['level_id'], array_column($data['items'], 'subject_id'));

        DB::transaction(function () use ($data) {
            foreach ($data['items'] as $it) {
                SubjectLevelSetting::updateOrCreate(
                    ['subject_id' => $it['subject_id'], 'level_id' => $data['level_id']],
                    ['coefficient' => ($it['coefficient'] === '' ? null : $it['coefficient'])]
                );
            }
        });

        return response()->json(['status' => 'success', 'message' => 'Coefficients enregistrés.'], 200);
    }

    /**
     * PUT /academic/subject-settings/report
     * { level_id, items: [{ subject_id, excluded }] } — excluded = retirée du bulletin.
     */
    public function saveReport(Request $request)
    {
        $data = $request->validate([
            'level_id'           => ['required', 'integer'],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.subject_id' => ['required', 'integer'],
            'items.*.excluded'   => ['required', 'boolean'],
        ]);

        $this->guard($data['level_id'], array_column($data['items'], 'subject_id'));

        DB::transaction(function () use ($data) {
            foreach ($data['items'] as $it) {
                SubjectLevelSetting::updateOrCreate(
                    ['subject_id' => $it['subject_id'], 'level_id' => $data['level_id']],
                    ['excluded_from_report' => (bool) $it['excluded']]
                );
            }
        });

        return response()->json(['status' => 'success', 'message' => 'Disciplines du bulletin enregistrées.'], 200);
    }

    /**
     * PUT /academic/subject-settings/bilan
     * { subject_ids: [int], bilan: 'lettres'|'sciences'|'autres'|null }
     * Affecte les matières cochées à un bilan (null = retirer du bilan).
     * Une matière principale entraîne ses sous-matières.
     */
    public function saveBilan(Request $request)
    {
        $data = $request->validate([
            'subject_ids'   => ['required', 'array', 'min:1'],
            'subject_ids.*' => ['integer'],
            'bilan'         => ['nullable', 'in:lettres,sciences,autres'],
        ]);

        $ids   = array_values(array_unique($data['subject_ids']));
        $valid = Subject::whereIn('id', $ids)->pluck('id')->all();
        abort_if(count($ids) !== count($valid), 422, "Matière invalide.");

        DB::transaction(function () use ($ids, $data) {
            Subject::where(fn ($q) => $q->whereIn('id', $ids)->orWhereIn('parent_id', $ids))
                ->update(['bilan' => $data['bilan'] ?? null]);
        });

        return response()->json(['status' => 'success', 'message' => 'Bilan enregistré.'], 200);
    }

    /**
     * GET /academic/subjects/{id}/coefficients
     * Coefficient de CETTE matière pour chaque niveau.
     */
    public function subjectCoefficients(string $id)
    {
        $subject  = Subject::findOrFail($id);
        $settings = SubjectLevelSetting::where('subject_id', $subject->id)->get()->keyBy('level_id');

        $rows = Level::with('cycle')->orderBy('order')->get()->map(function ($l) use ($settings) {
            $st = $settings->get($l->id);
            return [
                'level_id'             => $l->id,
                'level_name'           => $l->name,
                'cycle_name'           => $l->cycle->name ?? null,
                'coefficient'          => $st?->coefficient,
                'excluded_from_report' => (bool) ($st?->excluded_from_report ?? false),
            ];
        })->values();

        return response()->json([
            'subject' => ['id' => $subject->id, 'name' => $subject->name, 'code' => $subject->code],
            'levels'  => $rows,
        ], 200);
    }
}