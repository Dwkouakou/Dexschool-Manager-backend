<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\Export;

/**
 * Export Excel du rapport financier Transport — un classeur avec 4 onglets
 * distincts (Résumé, Par véhicule, Évolution mensuelle, Impayés), pour
 * pouvoir retravailler chaque section séparément dans un tableur.
 *
 * NOTE : les 4 petites classes "Sheet" ci-dessous sont volontairement
 * regroupées dans ce même fichier (plutôt qu'un fichier par classe) —
 * elles ne sont utilisées QUE par TransportReportExport et jamais
 * référencées ailleurs, donc les garder ensemble simplifie la
 * maintenance sans poser de problème d'autoload (PHP charge tout le
 * fichier dès que la classe principale est appelée).
 */
class TransportReportExport implements WithMultipleSheets, Export
{
    protected array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function sheets(): array
    {
        return [
            new TransportReportSummarySheet($this->data),
            new TransportReportVehiclesSheet($this->data['vehicles']),
            new TransportReportTrendSheet($this->data['monthly_trend']),
            new TransportReportUnpaidSheet($this->data['unpaid_list']),
        ];
    }
}

class TransportReportSummarySheet implements FromArray, WithHeadings, WithTitle
{
    protected array $data;
    public function __construct(array $data) { $this->data = $data; }

    public function title(): string { return 'Résumé'; }

    public function headings(): array
    {
        return ['Indicateur', 'Valeur (FCFA)'];
    }

    public function array(): array
    {
        $s = $this->data['summary'];
        return [
            ['Période', $this->data['period']['from'] . ' au ' . $this->data['period']['to']],
            ['Recettes encaissées', $s['total_receipts']],
            ['Carburant', $s['expenses']['fuel']],
            ['Lavage & Nettoyage', $s['expenses']['washing']],
            ['Entretien & Pannes', $s['expenses']['repair']],
            ['Assurances', $s['expenses']['insurance']],
            ['Salaires chauffeurs', $s['expenses']['salary']],
            ['Autres charges', $s['expenses']['other']],
            ['Total des charges', $s['total_expenses']],
            [$s['is_profit'] ? 'Bénéfice net' : 'Déficit net', abs($s['net_balance'])],
            ['Taux de couverture', $s['coverage_rate'] . ' %'],
        ];
    }
}

class TransportReportVehiclesSheet implements FromArray, WithHeadings, WithTitle
{
    protected $vehicles;
    public function __construct($vehicles) { $this->vehicles = $vehicles; }

    public function title(): string { return 'Par véhicule'; }

    public function headings(): array
    {
        return ['Véhicule', 'Immatriculation', 'Élèves actifs', 'Recettes (FCFA)', 'Charges (FCFA)', 'Solde net (FCFA)'];
    }

    public function array(): array
    {
        return collect($this->vehicles)->map(fn($v) => [
            $v['name'], $v['registration'], $v['students_count'], $v['revenue'], $v['expenses'], $v['net'],
        ])->toArray();
    }
}

class TransportReportTrendSheet implements FromArray, WithHeadings, WithTitle
{
    protected $trend;
    public function __construct($trend) { $this->trend = $trend; }

    public function title(): string { return 'Évolution mensuelle'; }

    public function headings(): array
    {
        return ['Mois', 'Recettes (FCFA)', 'Charges (FCFA)', 'Solde (FCFA)'];
    }

    public function array(): array
    {
        return collect($this->trend)->map(fn($m) => [
            $m['label'], $m['receipts'], $m['expenses'], $m['receipts'] - $m['expenses'],
        ])->toArray();
    }
}

class TransportReportUnpaidSheet implements FromArray, WithHeadings, WithTitle
{
    protected $unpaid;
    public function __construct($unpaid) { $this->unpaid = $unpaid; }

    public function title(): string { return 'Impayés'; }

    public function headings(): array
    {
        return ['Élève', 'Matricule', 'Classe', 'Ligne', 'Total dû (FCFA)', 'Payé (FCFA)', 'Reste à payer (FCFA)'];
    }

    public function array(): array
    {
        return collect($this->unpaid)->map(fn($u) => [
            $u['student_name'], $u['matricule'], $u['class_name'], $u['route_name'],
            $u['total_amount'], $u['amount_paid'], $u['remaining'],
        ])->toArray();
    }
}