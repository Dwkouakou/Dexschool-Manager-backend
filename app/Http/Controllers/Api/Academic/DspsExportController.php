<?php

namespace App\Http\Controllers\Api\Academic;

use App\Http\Controllers\Controller;
use App\Models\Academic\AcademicYears;
use App\Models\Academic\Classe;
use App\Models\Academic\Period;
use App\Services\ClassResultsService as R;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;

/**
 * Onglet « Générer moyenne DSPS » : fichier .xls des moyennes (modèle DSPS) par classe ou par niveau.
 *   - colonne A : matricule national | Nom | Série | Niveau | disciplines… | Bonus
 *   - 21 = non classé (matière enseignée mais pas de moyenne) ; 22 = discipline non enseignée
 *   - pour une matière à sous-matières (ex. Français), ce sont les moyennes des sous-matières qui sont exportées
 */
class DspsExportController extends Controller
{
    private const NC = 21;   // non classé
    private const NE = 22;   // non enseignée

    /** Colonnes du modèle DSPS (ordre fixe) => alias reconnus (noms/codes normalisés). */
    private const TEMPLATE = [
        'Composition Française' => ['composition francaise', 'composition', 'cf'],
        'Orthographe'           => ['orthographe', 'orthographe grammaire', 'og'],
        'Oral Français'         => ['oral francais', 'expression orale', 'oral', 'eo'],
        'Anglais'               => ['anglais', 'angl', 'ang'],
        'Philosophie'           => ['philosophie', 'philo'],
        'Allemand'              => ['allemand', 'all'],
        'Espagnol'              => ['espagnol', 'esp'],
        'Histoire-Géographie'   => ['histoire geographie', 'hist geo', 'hg'],
        'Mathématiques'         => ['mathematiques', 'maths', 'math'],
        'Sciences Physiques'    => ['sciences physiques', 'physique chimie', 'physique', 'pc', 'sp'],
        'SVT'                   => ['svt', 'sciences de la vie et de la terre'],
        'E.P.S'                 => ['eps', 'e p s', 'education physique et sportive', 'education physique'],
        'EDHC'                  => ['edhc', 'education aux droits de l homme et a la citoyennete'],
        'Arts Plastiques'       => ['arts plastiques', 'art plastique', 'ap'],
        'Musique'               => ['musique', 'education musicale', 'mus'],
        'TIC'                   => ['tic', 'tic a l ecole', 'informatique'],
        'Conduite'              => ['conduite', 'cond'],
    ];

    private function deny()
    {
        $u = Auth::user();
        $ok = $u && ($u->roles()->where('slug', 'admin')->exists() || $u->can('grades.view_all'));
        return $ok ? null : response()->json(['message' => "Cette section est réservée à l'administration."], 403);
    }

    private static function norm(?string $v): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii((string) $v))));
    }

    private function columnFor($subject): ?string
    {
        $n = self::norm($subject['name'] ?? '');
        $c = self::norm($subject['code'] ?? '');
        foreach (self::TEMPLATE as $col => $aliases) {
            if (in_array($n, $aliases, true) || ($c !== '' && in_array($c, $aliases, true))) return $col;
        }
        return null;
    }

    /** Feuilles « leaf » enseignées dans la classe : sous-matières si la matière en a, sinon la matière. */
    private function leaves(array $res): array
    {
        $out = [];
        foreach ($res['mains'] as $m) {
            if ($m['children']) {
                foreach ($m['children'] as $c) $out[] = ['id' => $c['id'], 'name' => $c['name'], 'code' => $c['code'], 'avg' => $res['childAvg'][$c['id']] ?? []];
            } else {
                $out[] = ['id' => $m['id'], 'name' => $m['name'], 'code' => $m['code'], 'avg' => $res['mainAvg'][$m['id']] ?? []];
            }
        }
        return $out;
    }

    /** @param Classe[]|\Illuminate\Support\Collection $classes */
    private function build($classes, Period $period, string $level, string $sheetName): array
    {
        $perClass = []; $extras = [];
        foreach ($classes as $classe) {
            $res = R::build($classe, $period);
            $leaves = $this->leaves($res);
            $map = [];
            foreach ($leaves as $lf) {
                $col = $this->columnFor($lf);
                if ($col) { $map[$col] ??= $lf; }
                else { $extras[$lf['name']] = true; }
            }
            $perClass[] = ['res' => $res, 'leaves' => $leaves, 'map' => $map];
        }

        $extraCols = array_keys($extras);
        sort($extraCols);
        $header = array_merge(['', 'Nom', 'Série', 'Niveau'], array_keys(self::TEMPLATE), ['Bonus'], $extraCols);

        $rows = [];
        foreach ($perClass as $pc) {
            $byName = [];
            foreach ($pc['leaves'] as $lf) $byName[$lf['name']] = $lf;
            foreach ($pc['res']['students'] as $s) {
                $any = false; $vals = [];
                foreach (array_keys(self::TEMPLATE) as $col) {
                    $lf = $pc['map'][$col] ?? null;
                    $vals[$col] = $lf === null ? self::NE : (isset($lf['avg'][$s->id]) ? $lf['avg'][$s->id] : self::NC);
                    if ($lf !== null && isset($lf['avg'][$s->id])) $any = true;
                }
                $ext = [];
                foreach ($extraCols as $e) {
                    $lf = $byName[$e] ?? null;
                    $ext[] = $lf === null ? self::NE : (isset($lf['avg'][$s->id]) ? $lf['avg'][$s->id] : self::NC);
                    if ($lf !== null && isset($lf['avg'][$s->id])) $any = true;
                }
                $rows[] = array_merge(
                    [$s->national_matricule ?: $s->matricule, mb_strtoupper(trim($s->last_name . ' ' . $s->first_name)), '', $level],
                    array_values($vals), [$any ? 0 : ''], $ext
                );
            }
        }
        usort($rows, fn ($a, $b) => strcasecmp($a[1], $b[1]));

        $sp = new Spreadsheet();
        $ws = $sp->getActiveSheet();
        $ws->setTitle($sheetName);
        $ws->fromArray($header, null, 'A1');
        if ($rows) $ws->fromArray($rows, null, 'A2');

        $tmp = tempnam(sys_get_temp_dir(), 'dsps');
        (new Xls($sp))->save($tmp);
        $bin = file_get_contents($tmp);
        @unlink($tmp);
        return ['count' => count($rows), 'content' => base64_encode($bin)];
    }

    private function fileSuffix(Period $period): string
    {
        $digits = preg_match('/\d+/', (string) $period->code, $m) ? $m[0] : (preg_match('/\d+/', (string) $period->name, $m2) ? $m2[0] : '1');
        $year = AcademicYears::find(current_viewing_year_id())->name ?? '';
        return "_dsps_trim{$digits}_" . preg_replace('/[^0-9\-]/', '', $year) . '.xls';
    }

    private function slug(string $v): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]+/', '', Str::ascii($v)));
    }

    private function levelLabel(Classe $classe): string
    {
        return strtoupper(preg_replace('/\s+/', '', Str::ascii((string) ($classe->level->name ?? ''))));
    }

    /** GET /report-cards/dsps/class?classe_id=&period_id= */
    public function byClass(Request $request)
    {
        if ($d = $this->deny()) return $d;
        $v = Validator::make($request->all(), ['classe_id' => 'required|integer', 'period_id' => 'required|integer']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $classe = Classe::with('level')->find($request->classe_id);
        $period = Period::find($request->period_id);
        if (!$classe || !$period) return response()->json(['message' => 'Classe ou période introuvable.'], 404);

        $out = $this->build([$classe], $period, $this->levelLabel($classe), 'moyennes');
        if (!$out['count']) return response()->json(['message' => 'Aucun élève dans cette classe.'], 422);

        return response()->json(['filename' => $this->slug($classe->name) . $this->fileSuffix($period), 'count' => $out['count'], 'content' => $out['content']], 200);
    }

    /** GET /report-cards/dsps/level?level_id=&period_id= */
    public function byLevel(Request $request)
    {
        if ($d = $this->deny()) return $d;
        $v = Validator::make($request->all(), ['level_id' => 'required|integer', 'period_id' => 'required|integer']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $period  = Period::find($request->period_id);
        $classes = Classe::with('level')->where('level_id', $request->level_id)->orderBy('name')->get();
        if (!$period || $classes->isEmpty()) return response()->json(['message' => 'Niveau ou période introuvable.'], 404);

        $label = $this->levelLabel($classes->first());
        $out = $this->build($classes, $period, $label, 'moyennes');
        if (!$out['count']) return response()->json(['message' => 'Aucun élève dans ce niveau.'], 422);

        return response()->json(['filename' => $this->slug($label) . $this->fileSuffix($period), 'count' => $out['count'], 'classes' => $classes->count(), 'content' => $out['content']], 200);
    }
}