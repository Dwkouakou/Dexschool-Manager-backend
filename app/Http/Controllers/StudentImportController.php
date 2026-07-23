<?php

namespace App\Http\Controllers;

use App\Models\Academic\Classe;
use App\Models\Academic\Student;
use App\Models\Academic\StudentAcademicRecord;
use App\Models\Academic\StudentParent;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class StudentImportController extends Controller
{
    /**
     * Parse le fichier et renvoie un APERÇU (sans enregistrer).
     * L'utilisateur choisit la classe dans un select AVANT d'importer.
     */
    public function preview(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xls,xlsx', 'max:5120'],
        ]);

        try {
            $rows = $this->parseFile($request->file('file'));
            return response()->json([
                'status' => 'success',
                'count'  => count($rows),
                'rows'   => $rows,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => "Impossible de lire le fichier : " . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Enregistre réellement les élèves, avec la classe choisie dans le select.
     */
    public function import(Request $request)
    {
        assert_writable_year();

        $request->validate([
            'file'     => ['required', 'file', 'mimes:xls,xlsx', 'max:5120'],
            'class_id' => ['required', 'exists:classes,id'],
        ]);

        // La classe doit appartenir à l'établissement (trait → scope auto)
        $classe = Classe::findOrFail($request->class_id);
        $academicYearId = $classe->academic_year_id;

        $rows = $this->parseFile($request->file('file'));

        // ─── VERROU DE SÉCURITÉ : CAPACITÉ MAXIMALE DE LA CLASSE ───
        $maxCapacity = (int) $classe->capacity;
        $currentCount = Student::where('class_id', $classe->id)
            ->where('academic_year_id', $academicYearId)
            ->count();

        $availableSeats = $maxCapacity - $currentCount;

        if ($availableSeats <= 0) {
            return response()->json([
                'status'  => 'error',
                'message' => "Import impossible : la classe '{$classe->name}' est déjà à sa capacité maximale ({$maxCapacity} places)."
            ], 422);
        }

        if (count($rows) > $availableSeats) {
            return response()->json([
                'status'  => 'error',
                'message' => "Import refusé : le fichier contient " . count($rows) . " élève(s), mais la classe '{$classe->name}' ne dispose plus que de {$availableSeats} place(s) disponible(s) (capacité totale : {$maxCapacity})."
            ], 422);
        }

        $created = 0; $skipped = 0; $errors = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $i => $r) {
                // Anti-doublon : national_matricule dans le même établissement
                $exists = Student::where('national_matricule', $r['national_matricule'])
                    ->when($r['national_matricule'], fn($q) => $q)->exists();
                if ($r['national_matricule'] && $exists) {
                    $skipped++;
                    continue;
                }

                // Génération matricule interne (préfixe établissement)
                $matricule = $this->generateMatricule();

                $student = Student::create([
                    'matricule'             => $matricule,
                    'national_matricule'    => $r['national_matricule'],
                    'provisional_matricule' => $r['provisional_matricule'],
                    'last_name'             => strtoupper($r['last_name']),
                    'first_name'            => ucwords(strtolower($r['first_name'])),
                    'gender'                => $r['gender'],
                    'birth_date'            => $r['birth_date'],
                    'birth_place'           => $r['birth_place'],
                    'nationality'           => $r['nationality'],
                    'origin_school'         => $r['origin_school'],
                    'address'               => null,
                    'class_id'              => $classe->id,          // classe du select
                    'academic_year_id'      => $academicYearId,
                    'is_active'             => true,
                    'is_transferred'        => $r['is_transferred'],
                    'is_enrolled'           => false,                // false à la création
                    'assignment_status'    => $r['assignment_status'],
                    'created_by'            => $request->user()->id,
                ]);

                // Tuteur → student_parents
                if ($r['tutor_name']) {
                    StudentParent::create([
                        'student_id'           => $student->id,
                        'type'                 => 'guardian',
                        'last_name'            => $r['tutor_name'],
                        'first_name'           => '',
                        'phone'                => $r['tutor_phone'] ?: '0000000000',
                        'is_main_contact'      => true,
                        'is_emergency_contact' => true,
                    ]);
                }

                // Situation de l'année → student_academic_records
                StudentAcademicRecord::create([
                    'student_id'        => $student->id,
                    'academic_year_id'  => $academicYearId,
                    'class_id'          => $classe->id,
                    'is_repeater'       => $r['is_repeater'],
                    'year_end_decision' => $r['year_end_decision'],
                    'lv2'               => $r['lv2'],
                    'art'               => $r['art'],
                ]);

                $created++;
            }

            DB::commit();
            return response()->json([
                'status'  => 'success',
                'message' => "$created élève(s) importé(s), $skipped ignoré(s) (déjà existants).",
                'created' => $created,
                'skipped' => $skipped,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => "Erreur pendant l'import : " . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Lit le fichier et retourne un tableau normalisé.
     */
    private function parseFile($file): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray(null, true, true, true); // clés = A, B, C...

        $rows = [];
        foreach ($data as $index => $row) {
            if ($index === 1) continue; // en-tête

            // Ligne vide ?
            if (empty($row['J']) && empty($row['K'])) continue;

            $rows[] = [
                'national_matricule'    => $this->clean($row['B']),   // Matricule
                'provisional_matricule' => $this->clean($row['C']),   // matric. prov.
                'code_national'         => $this->clean($row['D']),   // Code (matricule national)
                'assignment_status'     => stripos($row['E'] ?? '', 'non') !== false ? 'non_affecte' : 'affecte',
                'is_repeater'           => $this->toBool($row['F']),  // Redoub
                'is_transferred'        => $this->toBool($row['I']),  // Transf
                'last_name'             => $this->clean($row['J']),   // Nom
                'first_name'            => $this->clean($row['K']),   // Prénoms
                'lv2'                   => $this->clean($row['L']),
                'art'                   => $this->clean($row['M']),
                'gender'                => strtoupper(trim($row['N'] ?? '')) === 'F' ? 'F' : 'M',
                'birth_date'            => $this->parseFrenchDate($row['O']), // Né (le)
                'birth_place'           => $this->clean($row['P']),
                'tutor_name'            => $this->clean($row['Q']),   // Tuteur
                'tutor_phone'           => $this->clean(str_replace(' ', '', $row['R'] ?? '')),
                'origin_school'         => $this->clean($row['S']),
                'nationality'           => $this->clean($row['T']),
                // colonne classe (U) IGNORÉE
                'year_end_decision'     => $this->clean($row['V']),   // DFA
            ];
        }
        return $rows;
    }

    private function clean($v): ?string
    {
        if ($v === null) return null;
        $v = trim((string) $v);
        return ($v === '' || strtolower($v) === 'nan') ? null : $v;
    }

    private function toBool($v): bool
    {
        $v = strtolower(trim((string) $v));
        return in_array($v, ['true', '1', 'oui', 'vrai', 'o'], true);
    }

    /**
     * Convertit "05-août-10" → "2010-08-05".
     */
    private function parseFrenchDate($v): ?string
    {
        if (!$v) return null;

        // Si déjà une date Excel/datetime
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }

        $mois = [
            'janv' => '01', 'févr' => '02', 'fevr' => '02', 'mars' => '03',
            'avr' => '04', 'mai' => '05', 'juin' => '06', 'juil' => '07',
            'août' => '08', 'aout' => '08', 'sept' => '09', 'oct' => '10',
            'nov' => '11', 'déc' => '12', 'dec' => '12',
        ];

        $v = strtolower(trim($v));
        if (!preg_match('/(\d{1,2})[-\s]+([a-zûéè]+)\.?[-\s]+(\d{2,4})/u', $v, $m)) {
            return null;
        }

        $jour = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $moisTxt = rtrim($m[2], '.');
        $moisNum = $mois[$moisTxt] ?? null;
        if (!$moisNum) return null;

        $annee = $m[3];
        if (strlen($annee) === 2) {
            // 10 → 2010, 99 → 1999 (seuil à ajuster selon ton besoin)
            $annee = ((int)$annee <= 25) ? '20' . $annee : '19' . $annee;
        }

        return "{$annee}-{$moisNum}-{$jour}";
    }

    private function generateMatricule(): string
    {
        $prefix = current_establishment_prefix() . '-' . current_school_year_short() . '-';
        $last = Student::withTrashed()
            ->where('matricule', 'LIKE', $prefix . '%')
            ->orderBy('matricule', 'desc')
            ->first();
        $seq = $last ? ((int) substr($last->matricule, -4)) + 1 : 1;
        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}