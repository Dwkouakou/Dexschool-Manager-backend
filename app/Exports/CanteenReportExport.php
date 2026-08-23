<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\Export;

/**
 * Export Excel du rapport financier Cantine — 4 onglets.
 *
 * ─── NOTE v4 : la classe principale DOIT implémenter l'interface marqueur
 * Export en plus de WithMultipleSheets, sinon Excel::download() lève un
 * TypeError. Même correctif que celui appliqué à TransportReportExport.
 */
class CanteenReportExport implements WithMultipleSheets, Export
{
    protected array $data;
    protected string $from;
    protected string $to;

    public function __construct(array $data, string $from, string $to)
    {
        $this->data = $data;
        $this->from = $from;
        $this->to = $to;
    }

    public function sheets(): array
    {
        return [
            new CanteenReportSummarySheet($this->data['summary'], $this->from, $this->to),
            new CanteenReportExpensesSheet($this->data['expenses_by_category']),
            new CanteenReportTrendSheet($this->data['monthly_trend']),
            new CanteenReportUnpaidSheet($this->data['unpaid_list']),
        ];
    }
}

// ═══════════════════════════════════════════════════════════════════════
// ONGLET 1 — RÉSUMÉ
// ═══════════════════════════════════════════════════════════════════════
class CanteenReportSummarySheet implements FromArray, WithHeadings, WithTitle
{
    protected array $summary;
    protected string $from;
    protected string $to;

    public function __construct(array $summary, string $from, string $to)
    {
        $this->summary = $summary;
        $this->from = $from;
        $this->to = $to;
    }

    public function title(): string
    {
        return 'Résumé';
    }

    public function headings(): array
    {
        return ['Indicateur', 'Valeur'];
    }

    public function array(): array
    {
        return [
            ['Période', $this->from . ' au ' . $this->to],
            ['Recettes totales (FCFA)', $this->summary['total_receipts']],
            ['Dépenses totales (FCFA)', $this->summary['total_expenses']],
            ['Résultat net (FCFA)', $this->summary['net_balance']],
            ['Statut', $this->summary['is_profit'] ? 'Bénéfice' : 'Déficit'],
            ['Taux de couverture (%)', $this->summary['coverage_rate']],
        ];
    }
}

// ═══════════════════════════════════════════════════════════════════════
// ONGLET 2 — DÉPENSES PAR CATÉGORIE
// ═══════════════════════════════════════════════════════════════════════
class CanteenReportExpensesSheet implements FromArray, WithHeadings, WithTitle
{
    protected $expenses;

    public function __construct($expenses)
    {
        $this->expenses = $expenses;
    }

    public function title(): string
    {
        return 'Dépenses par catégorie';
    }

    public function headings(): array
    {
        return ['Catégorie', 'Montant total (FCFA)'];
    }

    public function array(): array
    {
        return collect($this->expenses)->map(fn ($row) => [
            $row['category'],
            $row['total'],
        ])->toArray();
    }
}

// ═══════════════════════════════════════════════════════════════════════
// ONGLET 3 — ÉVOLUTION MENSUELLE
// ═══════════════════════════════════════════════════════════════════════
class CanteenReportTrendSheet implements FromArray, WithHeadings, WithTitle
{
    protected $trend;

    public function __construct($trend)
    {
        $this->trend = $trend;
    }

    public function title(): string
    {
        return 'Évolution mensuelle';
    }

    public function headings(): array
    {
        return ['Mois', 'Recettes (FCFA)', 'Dépenses (FCFA)', 'Résultat (FCFA)'];
    }

    public function array(): array
    {
        return collect($this->trend)->map(fn ($row) => [
            $row['label'],
            $row['receipts'],
            $row['expenses'],
            $row['receipts'] - $row['expenses'],
        ])->toArray();
    }
}

// ═══════════════════════════════════════════════════════════════════════
// ONGLET 4 — IMPAYÉS / AVANCES
// ═══════════════════════════════════════════════════════════════════════
class CanteenReportUnpaidSheet implements FromArray, WithHeadings, WithTitle
{
    protected $unpaidList;

    public function __construct($unpaidList)
    {
        $this->unpaidList = $unpaidList;
    }

    public function title(): string
    {
        return 'Impayés';
    }

    public function headings(): array
    {
        return ['Élève', 'Matricule', 'Classe', 'Forfait', 'Total dû (FCFA)', 'Déjà payé (FCFA)', 'Reste à payer (FCFA)', 'Statut'];
    }

    public function array(): array
    {
        return collect($this->unpaidList)->map(fn ($row) => [
            $row['student_name'],
            $row['matricule'],
            $row['class_name'],
            $row['meal_type'],
            $row['total_amount'],
            $row['amount_paid'],
            $row['remaining'],
            $row['payment_status'] === 'partial' ? 'Avance' : 'Impayé',
        ])->toArray();
    }
}