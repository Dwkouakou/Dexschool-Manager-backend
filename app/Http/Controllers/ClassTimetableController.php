<?php

namespace App\Http\Controllers;

use App\Models\Academic\Classe;
use App\Models\Academic\ClassroomSubjectTeacher;
use App\Models\Academic\ClassTimetable;
use App\Models\Academic\ScheduleSetting;
use App\Models\Academic\Subject;
use App\Models\Academic\TimeSlot;
use App\Models\Academic\TimetableEntry;
use App\Models\Personel\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ClassTimetableController extends Controller
{
    private const DAY_NAMES = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

    private function days(): array
    {
        $s = ScheduleSetting::first();
        return $s ? array_values($s->days) : [1, 2, 3, 4, 5];
    }

    private function slots()
    {
        return TimeSlot::orderBy('start_time')->get()->map(fn ($s) => [
            'id'         => $s->id,
            'start_time' => substr($s->start_time, 0, 5),
            'end_time'   => substr($s->end_time, 0, 5),
            'type'       => $s->type,
        ])->values();
    }

    /** GET /academic/class-timetables — liste des classes + état de leur emploi du temps */
    public function index()
    {
        $classes = Classe::with('level')->orderBy('name')->get();

        $counts    = TimetableEntry::selectRaw('classe_id, COUNT(*) AS c')->groupBy('classe_id')->pluck('c', 'classe_id');
        $published = ClassTimetable::pluck('is_published', 'classe_id');

        $rows = $classes->map(fn ($c) => [
            'id'            => $c->id,
            'name'          => $c->name,
            'level_id'      => $c->level_id,
            'level_name'    => $c->level->name ?? null,
            'entries_count' => (int) ($counts[$c->id] ?? 0),
            'has_timetable' => ($counts[$c->id] ?? 0) > 0,
            'is_published'  => (bool) ($published[$c->id] ?? false) && ($counts[$c->id] ?? 0) > 0,
        ])->values();

        $levels = $rows->unique('level_id')->filter(fn ($r) => $r['level_id'])
            ->map(fn ($r) => ['id' => $r['level_id'], 'name' => $r['level_name']])->values();

        return response()->json([
            'classes' => $rows,
            'levels'  => $levels,
            'stats'   => [
                'total'      => $rows->count(),
                'planned'    => $rows->where('has_timetable', true)->count(),
                'unplanned'  => $rows->where('has_timetable', false)->count(),
            ],
        ], 200);
    }

    /** GET /academic/class-timetables/{classeId} — grille complète d'une classe */
    public function show(string $classeId)
    {
        $classe = Classe::with('level')->find($classeId);
        if (!$classe) return response()->json(['status' => 'error', 'message' => 'Classe introuvable.'], 404);

        $atts      = ClassroomSubjectTeacher::where('classe_id', $classe->id)->get();
        $subjects  = Subject::whereIn('id', $atts->pluck('subject_id'))->get()->keyBy('id');
        $employees = Employee::whereIn('id', $atts->pluck('employee_id'))->get()->keyBy('id');

        $subjectRows = $atts->filter(fn ($a) => $subjects->has($a->subject_id))->map(function ($a) use ($subjects, $employees) {
            $t = $employees->get($a->employee_id);
            return [
                'subject_id'   => $a->subject_id,
                'name'         => $subjects[$a->subject_id]->name,
                'code'         => $subjects[$a->subject_id]->code,
                'teacher_id'   => $a->employee_id,
                'teacher_name' => $t ? trim($t->last_name . ' ' . $t->first_name) : null,
            ];
        })->sortBy('name')->values();

        $entries = TimetableEntry::where('classe_id', $classe->id)->get(['day', 'time_slot_id', 'subject_id', 'room_id']);

        return response()->json([
            'classe'       => ['id' => $classe->id, 'name' => $classe->name, 'level_name' => $classe->level->name ?? null],
            'days'         => $this->days(),
            'slots'        => $this->slots(),
            'subjects'     => $subjectRows,
            'rooms'        => [],   // alimenté plus tard par le module « planning salle »
            'entries'      => $entries,
            'is_published' => (bool) ClassTimetable::where('classe_id', $classe->id)->value('is_published'),
        ], 200);
    }

    /**
     * PUT /academic/class-timetables/{classeId}
     * { entries: [{day, time_slot_id, subject_id|null, room_id|null}] } — remplace toute la grille.
     */
    public function save(Request $request, string $classeId)
    {
        assert_writable_year();

        $classe = Classe::find($classeId);
        if (!$classe) return response()->json(['status' => 'error', 'message' => 'Classe introuvable.'], 404);

        $validator = Validator::make($request->all(), [
            'entries'                => ['present', 'array'],
            'entries.*.day'          => ['required', 'integer', 'between:1,7'],
            'entries.*.time_slot_id' => ['required', 'integer'],
            'entries.*.subject_id'   => ['nullable', 'integer'],
            'entries.*.room_id'      => ['nullable', 'integer'],
        ]);
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

        $entries = collect($request->entries)->filter(fn ($e) => !empty($e['subject_id']))->values();

        $days  = $this->days();
        $slots = TimeSlot::pluck('type', 'id');
        $atts  = ClassroomSubjectTeacher::where('classe_id', $classe->id)->get()->keyBy('subject_id');

        $seen = [];
        foreach ($entries as $e) {
            $k = $e['day'] . '-' . $e['time_slot_id'];
            if (isset($seen[$k])) return $this->fail('Une même case est renseignée deux fois.');
            $seen[$k] = true;

            if (!in_array((int) $e['day'], $days, true))  return $this->fail("Ce jour n'est pas un jour de cours.");
            if (!$slots->has($e['time_slot_id']))          return $this->fail('Plage horaire inconnue.');
            if ($slots[$e['time_slot_id']] === 'recreation') return $this->fail('On ne peut pas placer un cours pendant une récréation.');
            if (!$atts->has($e['subject_id']))             return $this->fail("Une matière choisie n'est pas attribuée à cette classe.");
        }

        // ── Conflits enseignants : même professeur, même jour, même plage, autre classe ──
        $others = TimetableEntry::where('classe_id', '!=', $classe->id)->get();
        $otherAtts = ClassroomSubjectTeacher::whereIn('classe_id', $others->pluck('classe_id')->unique())
            ->get()->keyBy(fn ($a) => $a->classe_id . '-' . $a->subject_id);
        $classNames = Classe::whereIn('id', $others->pluck('classe_id')->unique())->pluck('name', 'id');

        $busy = [];
        foreach ($others as $o) {
            $teacher = $otherAtts->get($o->classe_id . '-' . $o->subject_id)?->employee_id;
            if ($teacher) $busy[$o->day . '-' . $o->time_slot_id . '-' . $teacher] = $classNames[$o->classe_id] ?? 'une autre classe';
        }

        foreach ($entries as $e) {
            $teacher = $atts[$e['subject_id']]->employee_id;
            $key = $e['day'] . '-' . $e['time_slot_id'] . '-' . $teacher;
            if (isset($busy[$key])) {
                $t = Employee::find($teacher);
                $slot = TimeSlot::find($e['time_slot_id']);
                return $this->fail(trim(($t->last_name ?? '') . ' ' . ($t->first_name ?? '')) . ' est déjà en cours avec ' . $busy[$key]
                    . ' le ' . self::DAY_NAMES[(int) $e['day']] . ' à ' . substr($slot->start_time, 0, 5) . '.');
            }
        }

        $yearId = current_active_year_id();

        DB::transaction(function () use ($classe, $entries, $yearId) {
            TimetableEntry::where('classe_id', $classe->id)->delete();
            foreach ($entries as $e) {
                TimetableEntry::create([
                    'academic_year_id' => $yearId,
                    'classe_id'        => $classe->id,
                    'time_slot_id'     => $e['time_slot_id'],
                    'day'              => $e['day'],
                    'subject_id'       => $e['subject_id'],
                    'room_id'          => $e['room_id'] ?? null,
                ]);
            }
            if ($entries->isEmpty()) ClassTimetable::where('classe_id', $classe->id)->delete();
        });

        return response()->json([
            'status'  => 'success',
            'message' => $entries->isEmpty() ? "L'emploi du temps de la classe est vide." : 'Emploi du temps enregistré.',
            'count'   => $entries->count(),
        ], 200);
    }

    /** POST /academic/class-timetables/publish { classe_ids: [], published: bool } */
    public function publish(Request $request)
    {
        assert_writable_year();

        $data = $request->validate([
            'classe_ids'   => ['required', 'array', 'min:1'],
            'classe_ids.*' => ['integer'],
            'published'    => ['required', 'boolean'],
        ]);

        $ids = Classe::whereIn('id', $data['classe_ids'])->pluck('id');
        $withEntries = TimetableEntry::whereIn('classe_id', $ids)->distinct()->pluck('classe_id');

        $targets = $data['published'] ? $ids->intersect($withEntries) : $ids;
        $skipped = $ids->count() - $targets->count();

        foreach ($targets as $cid) {
            ClassTimetable::updateOrCreate(
                ['classe_id' => $cid],
                ['academic_year_id' => current_active_year_id(), 'is_published' => $data['published']]
            );
        }

        $msg = $targets->count() . ' classe(s) ' . ($data['published'] ? 'publiée(s) aux professeurs' : 'masquée(s) aux professeurs') . '.';
        if ($skipped) $msg .= " $skipped classe(s) sans emploi du temps ignorée(s).";

        return response()->json(['status' => 'success', 'message' => $msg, 'updated' => $targets->count()], 200);
    }

    /** DELETE /academic/class-timetables/{classeId} — réinitialise l'emploi du temps de la classe */
    public function reset(string $classeId)
    {
        assert_writable_year();

        $classe = Classe::find($classeId);
        if (!$classe) return response()->json(['status' => 'error', 'message' => 'Classe introuvable.'], 404);

        DB::transaction(function () use ($classe) {
            TimetableEntry::where('classe_id', $classe->id)->delete();
            ClassTimetable::where('classe_id', $classe->id)->delete();
        });

        return response()->json(['status' => 'success', 'message' => "Emploi du temps de {$classe->name} réinitialisé."], 200);
    }

    private function fail(string $message)
    {
        return response()->json(['errors' => ['entries' => [$message]], 'message' => $message], 422);
    }
}