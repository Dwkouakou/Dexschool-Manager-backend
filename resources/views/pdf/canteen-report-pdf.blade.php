<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Rapport Financier Cantine</title>
    <style>
        @page { margin: 20px; }
        body { font-family: sans-serif; font-size: 11px; color: #221911; margin: 0; }
        .header { text-align: center; border-bottom: 2px solid #3D2E22; padding-bottom: 10px; margin-bottom: 14px; }
        .school-name { font-size: 16px; font-weight: bold; color: #3D2E22; margin: 0; }
        .report-title { text-align: center; font-size: 14px; font-weight: bold; color: #E67E22; text-transform: uppercase; margin: 10px 0; letter-spacing: 1px; }
        .period { text-align: center; font-size: 11px; color: #666666; margin-bottom: 16px; }
        h2 { font-size: 13px; color: #3D2E22; border-bottom: 1px solid #EEE5DE; padding-bottom: 4px; margin-top: 20px; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th { background-color: #FAF7F5; color: #3D2E22; font-size: 10px; text-align: left; padding: 6px 8px; border-bottom: 2px solid #EEE5DE; }
        td { font-size: 10.5px; padding: 5px 8px; border-bottom: 1px solid #F5F0EC; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .kpi-row { width: 100%; margin-bottom: 14px; }
        .kpi-box { display: inline-block; width: 23%; text-align: center; background-color: #FAF7F5; border: 1px solid #EEE5DE; border-radius: 6px; padding: 10px 4px; margin-right: 1%; }
        .kpi-label { font-size: 9px; color: #666666; text-transform: uppercase; }
        .kpi-value { font-size: 15px; font-weight: bold; color: #3D2E22; margin-top: 4px; }
        .profit { color: #27AE60; }
        .deficit { color: #C0395A; }
        .footer { margin-top: 20px; padding-top: 8px; border-top: 1px solid #EEE5DE; font-size: 9px; color: #999999; text-align: center; }
    </style>
</head>
<body>

    <div class="header">
        <p class="school-name">{{ $establishment->name ?? 'Groupe Scolaire' }}</p>
    </div>

    <div class="report-title">Rapport Financier — Cantine Scolaire</div>
    <div class="period">Période du {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} au {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}</div>

    <div class="kpi-row">
        <div class="kpi-box">
            <div class="kpi-label">Recettes</div>
            <div class="kpi-value">{{ number_format($summary['total_receipts'], 0, '', ' ') }} FCFA</div>
        </div>
        <div class="kpi-box">
            <div class="kpi-label">Dépenses</div>
            <div class="kpi-value">{{ number_format($summary['total_expenses'], 0, '', ' ') }} FCFA</div>
        </div>
        <div class="kpi-box">
            <div class="kpi-label">Résultat</div>
            <div class="kpi-value {{ $summary['is_profit'] ? 'profit' : 'deficit' }}">
                {{ $summary['is_profit'] ? '+' : '-' }}{{ number_format(abs($summary['net_balance']), 0, '', ' ') }} FCFA
            </div>
        </div>
        <div class="kpi-box">
            <div class="kpi-label">Couverture</div>
            <div class="kpi-value">{{ $summary['coverage_rate'] }}%</div>
        </div>
    </div>

    <h2>Dépenses par catégorie</h2>
    <table>
        <thead>
            <tr><th>Catégorie</th><th class="text-right">Montant</th></tr>
        </thead>
        <tbody>
            @forelse($expenses_by_category as $row)
                <tr>
                    <td>{{ $row['category'] }}</td>
                    <td class="text-right">{{ number_format($row['total'], 0, '', ' ') }} FCFA</td>
                </tr>
            @empty
                <tr><td colspan="2" class="text-center">Aucune dépense sur cette période.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Recouvrement (état actuel des abonnements)</h2>
    <table>
        <thead>
            <tr><th>Statut</th><th class="text-center">Nombre</th><th class="text-right">Montant total</th></tr>
        </thead>
        <tbody>
            <tr><td>Soldés</td><td class="text-center">{{ $recovery['paid']['count'] }}</td><td class="text-right">{{ number_format($recovery['paid']['total'], 0, '', ' ') }} FCFA</td></tr>
            <tr><td>Avances / Partiels</td><td class="text-center">{{ $recovery['partial']['count'] }}</td><td class="text-right">{{ number_format($recovery['partial']['total'], 0, '', ' ') }} FCFA</td></tr>
            <tr><td>Impayés</td><td class="text-center">{{ $recovery['unpaid']['count'] }}</td><td class="text-right">{{ number_format($recovery['unpaid']['total'], 0, '', ' ') }} FCFA</td></tr>
        </tbody>
    </table>

    <h2>Évolution mensuelle (6 derniers mois)</h2>
    <table>
        <thead>
            <tr><th>Mois</th><th class="text-right">Recettes</th><th class="text-right">Dépenses</th><th class="text-right">Résultat</th></tr>
        </thead>
        <tbody>
            @foreach($monthly_trend as $row)
                <tr>
                    <td>{{ ucfirst($row['label']) }}</td>
                    <td class="text-right">{{ number_format($row['receipts'], 0, '', ' ') }} FCFA</td>
                    <td class="text-right">{{ number_format($row['expenses'], 0, '', ' ') }} FCFA</td>
                    <td class="text-right">{{ number_format($row['receipts'] - $row['expenses'], 0, '', ' ') }} FCFA</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Liste des impayés / avances (triée par reste dû)</h2>
    <table>
        <thead>
            <tr><th>Élève</th><th>Classe</th><th>Forfait</th><th class="text-right">Reste à payer</th></tr>
        </thead>
        <tbody>
            @forelse($unpaid_list as $row)
                <tr>
                    <td>{{ $row['student_name'] }} ({{ $row['matricule'] }})</td>
                    <td>{{ $row['class_name'] }}</td>
                    <td>{{ $row['meal_type'] }}</td>
                    <td class="text-right">{{ number_format($row['remaining'], 0, '', ' ') }} FCFA</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center">Aucun impayé — tous les comptes sont soldés.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Document généré automatiquement par DexSchool Manager le {{ $generated_at->format('d/m/Y à H:i') }}.
    </div>

</body>
</html>