<?php

namespace App\Http\Controllers;

use App\Models\Academic\ScheduleSetting;
use App\Models\Academic\TimeSlot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TimeSlotController extends Controller
{
    private const DEFAULT_DAYS = [1, 2, 3, 4, 5];

    private function toMinutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', substr($hhmm, 0, 5)));
        return $h * 60 + $m;
    }

    private function format(TimeSlot $s): array
    {
        $start = substr($s->start_time, 0, 5);
        $end   = substr($s->end_time, 0, 5);

        return [
            'id'               => $s->id,
            'start_time'       => $start,
            'end_time'         => $end,
            'type'             => $s->type,
            'duration_minutes' => $this->toMinutes($end) - $this->toMinutes($start),
        ];
    }

    /** Valide les champs communs ; renvoie [erreurs|null, start, end]. */
    private function validateSlot(Request $request, ?int $ignoreId = null)
    {
        $validator = Validator::make($request->all(), [
            'start_time' => ['required', 'date_format:H:i'],
            'end_time'   => ['required', 'date_format:H:i'],
            'type'       => ['required', Rule::in(TimeSlot::TYPES)],
        ], [
            'start_time.date_format' => "L'heure de début est invalide.",
            'end_time.date_format'   => "L'heure de fin est invalide.",
            'type.in'                => 'Type de plage invalide.',
        ]);

        if ($validator->fails()) {
            return [$validator->errors()->toArray(), null, null];
        }

        $start = $this->toMinutes($request->start_time);
        $end   = $this->toMinutes($request->end_time);

        if ($end <= $start) {
            return [['end_time' => ["L'heure de fin doit être après l'heure de début."]], null, null];
        }

        // Chevauchement avec une autre plage de l'établissement
        $overlap = TimeSlot::when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('start_time', '<', $request->end_time . ':00')
            ->where('end_time', '>', $request->start_time . ':00')
            ->first();

        if ($overlap) {
            return [['start_time' => [
                'Cette plage chevauche ' . substr($overlap->start_time, 0, 5) . ' → ' . substr($overlap->end_time, 0, 5) . '.',
            ]], null, null];
        }

        return [null, $request->start_time, $request->end_time];
    }

    /** GET /academic/time-slots */
    public function index()
    {
        $setting = ScheduleSetting::first();

        return response()->json([
            'days'            => $setting ? array_values($setting->days) : self::DEFAULT_DAYS,
            'days_configured' => (bool) $setting,
            'slots'           => TimeSlot::orderBy('start_time')->get()->map(fn ($s) => $this->format($s))->values(),
        ], 200);
    }

    /** POST /academic/time-slots */
    public function store(Request $request)
    {
        [$errors] = $this->validateSlot($request);
        if ($errors) return response()->json(['errors' => $errors], 422);

        $slot = TimeSlot::create($request->only('start_time', 'end_time', 'type'));

        return response()->json([
            'status'  => 'success',
            'message' => 'Plage horaire enregistrée.',
            'data'    => $this->format($slot),
        ], 201);
    }

    /** PUT /academic/time-slots/{id} */
    public function update(Request $request, string $id)
    {
        $slot = TimeSlot::find($id);
        if (!$slot) return response()->json(['status' => 'error', 'message' => 'Plage horaire introuvable.'], 404);

        [$errors] = $this->validateSlot($request, $slot->id);
        if ($errors) return response()->json(['errors' => $errors], 422);

        $slot->update($request->only('start_time', 'end_time', 'type'));

        return response()->json([
            'status'  => 'success',
            'message' => 'Plage horaire mise à jour.',
            'data'    => $this->format($slot->fresh()),
        ], 200);
    }

    /** DELETE /academic/time-slots/{id} */
    public function destroy(string $id)
    {
        $slot = TimeSlot::find($id);
        if (!$slot) return response()->json(['status' => 'error', 'message' => 'Plage horaire introuvable.'], 404);

        if (\Illuminate\Support\Facades\Schema::hasTable('timetable_entries')
            && \Illuminate\Support\Facades\DB::table('timetable_entries')->where('time_slot_id', $slot->id)->exists()) {
            return response()->json([
                'status'  => 'error',
                'message' => "Suppression impossible : cette plage est utilisée dans des emplois du temps de classes.",
            ], 422);
        }

        $slot->delete();

        return response()->json(['status' => 'success', 'message' => 'Plage horaire supprimée.'], 200);
    }

    /** PUT /academic/time-slots-days  { days: [1..7] } */
    public function saveDays(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'days'   => ['required', 'array', 'min:1', 'max:7'],
            'days.*' => ['integer', 'between:1,7', 'distinct'],
        ], [
            'days.required' => 'Sélectionnez au moins un jour de cours.',
            'days.min'      => 'Sélectionnez au moins un jour de cours.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $days = collect($request->days)->map(fn ($d) => (int) $d)->sort()->values()->all();

        $setting = ScheduleSetting::first();
        $setting ? $setting->update(['days' => $days]) : ScheduleSetting::create(['days' => $days]);

        return response()->json([
            'status'  => 'success',
            'message' => count($days) . ' jour(s) de cours enregistré(s).',
            'days'    => $days,
        ], 200);
    }
}