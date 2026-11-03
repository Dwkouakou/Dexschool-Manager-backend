<?php

namespace App\Http\Controllers;

use App\Models\Academic\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SubjectController extends Controller
{
    /**
     * GET /academic/subjects[?active=1][&main=1]
     * Liste PLATE (matières principales + sous-matières, avec parent_id).
     * ?active=1 : uniquement les actives — ?main=1 : uniquement les matières principales.
     */
    public function index(Request $request)
    {
        $query = Subject::orderBy('name', 'asc');

        if ($request->boolean('active')) $query->where('is_active', true);
        if ($request->boolean('main'))   $query->whereNull('parent_id');

        return response()->json($query->get(), 200);
    }

    /** Règle d'unicité du code, limitée à l'établissement courant. */
    private function codeUniqueRule(?string $ignoreId = null)
    {
        $rule = Rule::unique('subjects', 'code')
            ->where(fn ($q) => $q->where('establishment_id', current_establishment_id()));

        return $ignoreId ? $rule->ignore($ignoreId) : $rule;
    }

    /**
     * Nom déjà utilisé ? Entre matières principales pour une matière principale,
     * entre « sœurs » (même parent) pour une sous-matière.
     */
    private function nameTaken(string $name, ?int $parentId, ?string $ignoreId = null): bool
    {
        return Subject::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($parentId, fn ($q) => $q->where('parent_id', $parentId), fn ($q) => $q->whereNull('parent_id'))
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }

    private function normalize(array $input): array
    {
        $input['name'] = trim((string) ($input['name'] ?? ''));
        $input['code'] = strtoupper(trim((string) ($input['code'] ?? '')));
        return $input;
    }

    private const MESSAGES = [
        'code.unique'          => 'Ce code est déjà utilisé par une autre matière.',
        'code.regex'           => 'Le code doit contenir entre 2 et 20 caractères alphanumériques (A-Z, 0-9).',
        'children.*.code.regex'=> 'Le code d\'une sous-matière doit contenir entre 2 et 20 caractères alphanumériques (A-Z, 0-9).',
    ];

    private function makeSubject(string $name, string $code, ?int $parentId): Subject
    {
        $subject = new Subject();
        $subject->forceFill([
            'name' => $name, 'code' => $code, 'is_active' => true, 'parent_id' => $parentId,
        ])->save();
        return $subject;
    }

    /**
     * POST /academic/subjects
     * { name, code, children?: [{name, code}], parent_id? }
     * - parent_id : crée UNE sous-matière sous une matière principale existante.
     * - children  : crée la matière principale ET ses sous-matières en une fois.
     */
    public function store(Request $request)
    {
        $input = $this->normalize($request->all());
        if (isset($input['children']) && is_array($input['children'])) {
            $input['children'] = array_map(fn ($c) => $this->normalize((array) $c), $input['children']);
        }
        $request->merge($input);

        $validator = Validator::make($request->all(), [
            'name'            => ['required', 'string', 'max:255'],
            'code'            => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9]{2,20}$/', $this->codeUniqueRule()],
            'parent_id'       => ['nullable', 'integer'],
            'children'        => ['nullable', 'array', 'max:20'],
            'children.*.name' => ['required', 'string', 'max:255'],
            'children.*.code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9]{2,20}$/', $this->codeUniqueRule()],
        ], self::MESSAGES + [
            'children.*.code.unique' => 'Le code d\'une sous-matière est déjà utilisé par une autre matière.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $parentId = null;
        if ($request->filled('parent_id')) {
            $parent = Subject::find($request->parent_id);
            if (!$parent) {
                return response()->json(['errors' => ['parent_id' => ['Matière principale introuvable.']]], 422);
            }
            if ($parent->parent_id) {
                return response()->json(['errors' => ['parent_id' => ['Une sous-matière ne peut pas avoir de sous-matières.']]], 422);
            }
            if ($request->filled('children')) {
                return response()->json(['errors' => ['children' => ['Une sous-matière ne peut pas avoir de sous-matières.']]], 422);
            }
            $parentId = $parent->id;
        }

        if ($this->nameTaken($request->name, $parentId)) {
            return response()->json(['errors' => ['name' => [$parentId
                ? 'Cette matière a déjà une sous-matière portant ce nom.'
                : 'Une matière portant ce nom existe déjà.']]], 422);
        }

        // Doublons dans la saisie elle-même
        $children = $request->input('children', []);
        $codes = array_merge([$request->code], array_column($children, 'code'));
        if (count($codes) !== count(array_unique($codes))) {
            return response()->json(['errors' => ['children' => ['Les codes saisis doivent tous être différents.']]], 422);
        }
        $names = array_map('mb_strtolower', array_column($children, 'name'));
        if (count($names) !== count(array_unique($names))) {
            return response()->json(['errors' => ['children' => ['Deux sous-matières portent le même nom.']]], 422);
        }

        $created = [];
        $subject = DB::transaction(function () use ($request, $parentId, $children, &$created) {
            $main = $this->makeSubject($request->name, $request->code, $parentId);
            foreach ($children as $c) {
                $created[] = $this->makeSubject($c['name'], $c['code'], $main->id);
            }
            return $main;
        });

        return response()->json([
            'status'   => 'success',
            'message'  => $parentId ? 'Sous-matière créée avec succès !'
                : (count($created) ? 'Matière et sous-matières créées avec succès !' : 'Matière créée avec succès !'),
            'data'     => $subject,
            'children' => $created,
        ], 201);
    }

    /** PUT /academic/subjects/{id} */
    public function update(Request $request, string $id)
    {
        $subject = Subject::findOrFail($id);

        if ($request->has('name')) $request->merge(['name' => trim((string) $request->name)]);
        if ($request->has('code')) $request->merge(['code' => strtoupper(trim((string) $request->code))]);

        $validator = Validator::make($request->all(), [
            'name'      => ['sometimes', 'string', 'max:255'],
            'code'      => ['sometimes', 'string', 'max:20', 'regex:/^[A-Z0-9]{2,20}$/', $this->codeUniqueRule($id)],
            'is_active' => ['sometimes', 'boolean'],
        ], self::MESSAGES);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($request->filled('name') && $this->nameTaken($request->name, $subject->parent_id, $id)) {
            return response()->json(['errors' => ['name' => ['Ce nom est déjà utilisé.']]], 422);
        }

        $active = $request->has('is_active') ? $request->boolean('is_active') : $subject->is_active;

        if ($subject->parent_id && $active && !$subject->is_active) {
            $parent = Subject::find($subject->parent_id);
            if ($parent && !$parent->is_active) {
                return response()->json(['errors' => ['is_active' => ['Activez d\'abord la matière principale.']]], 422);
            }
        }

        $changedChildren = [];
        DB::transaction(function () use ($subject, $request, $active, &$changedChildren) {
            $subject->update([
                'name'      => $request->filled('name') ? $request->name : $subject->name,
                'code'      => $request->filled('code') ? $request->code : $subject->code,
                'is_active' => $active,
            ]);

            // Les sous-matières suivent l'état de leur matière principale
            if (!$subject->parent_id && $request->has('is_active')) {
                Subject::where('parent_id', $subject->id)->update(['is_active' => $active]);
                $changedChildren = Subject::where('parent_id', $subject->id)->pluck('id')->all();
            }
        });

        return response()->json([
            'status'   => 'success',
            'message'  => 'Matière mise à jour.',
            'data'     => $subject,
            'children_ids' => $changedChildren,
        ], 200);
    }

    /** DELETE /academic/subjects/{id} — refusée si la matière est utilisée ou a des sous-matières. */
    public function destroy(string $id)
    {
        $subject = Subject::findOrFail($id);

        if (Subject::where('parent_id', $subject->id)->exists()) {
            return response()->json([
                'status'  => 'error',
                'message' => "Suppression impossible : cette matière possède des sous-matières. Supprimez-les d'abord.",
            ], 422);
        }

        $usedIn = [];
        foreach (['classroom_subject_teachers' => 'attribution à une classe',
                  'evaluations'                => 'évaluation',
                  'subject_averages'           => 'moyenne de matière'] as $table => $label) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'subject_id')
                && DB::table($table)->where('subject_id', $subject->id)->exists()) {
                $usedIn[] = $label;
            }
        }

        if ($usedIn) {
            return response()->json([
                'status'  => 'error',
                'message' => "Suppression impossible : cette matière est utilisée (" . implode(', ', $usedIn)
                    . "). Désactivez-la plutôt.",
            ], 422);
        }

        DB::transaction(function () use ($subject) {
            DB::table('subject_level_settings')->where('subject_id', $subject->id)->delete();
            $subject->delete();
        });

        return response()->json(['status' => 'success', 'message' => 'Matière supprimée.'], 200);
    }
}