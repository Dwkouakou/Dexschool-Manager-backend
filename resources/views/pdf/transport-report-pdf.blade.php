<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
  @page { margin: 30px; }
  body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #3D2E22; }
  h1 { font-size: 18px; color: #3D2E22; margin: 0 0 2px; }
  .subtitle { font-size: 11px; color: #8C7B6B; margin: 0 0 20px; }
  h2 { font-size: 13px; color: #3D2E22; border-bottom: 2px solid #E67E22; padding-bottom: 4px; margin: 22px 0 10px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
  th { background: #F5EFE9; text-align: left; padding: 6px 8px; font-size: 10px; text-transform: uppercase; color: #8C7B6B; }
  td { padding: 6px 8px; border-bottom: 1px solid #F2EDE8; font-size: 11px; }
  .text-end { text-align: right; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 9px; font-weight: bold; color: #fff; }
  .profit { background: #27AE60; }
  .deficit { background: #C0395A; }
  .summary-box { display: table; width: 100%; margin-bottom: 10px; }
  .summary-cell { display: table-cell; width: 33%; padding: 10px; background: #FAF7F5; border-radius: 8px; }
</style>
</head>
<body>

  <h1>{{ $establishmentName }}</h1>
  <p class="subtitle">Rapport financier — Module Transport Scolaire &middot; Période du {{ \Carbon\Carbon::parse($data['period']['from'])->format('d/m/Y') }} au {{ \Carbon\Carbon::parse($data['period']['to'])->format('d/m/Y') }}</p>

  <h2>Résumé financier</h2>
  <table>
    <tr><td>Recettes encaissées</td><td class="text-end"><strong>{{ number_format($data['summary']['total_receipts'], 0, '', ' ') }} FCFA</strong></td></tr>
    <tr><td>Total des charges</td><td class="text-end"><strong>{{ number_format($data['summary']['total_expenses'], 0, '', ' ') }} FCFA</strong></td></tr>
    <tr>
      <td>{{ $data['summary']['is_profit'] ? 'Bénéfice net' : 'Déficit net' }}</td>
      <td class="text-end">
        <span class="badge {{ $data['summary']['is_profit'] ? 'profit' : 'deficit' }}">
          {{ number_format(abs($data['summary']['net_balance']), 0, '', ' ') }} FCFA
        </span>
      </td>
    </tr>
    <tr><td>Taux de couverture des charges</td><td class="text-end">{{ $data['summary']['coverage_rate'] }} %</td></tr>
  </table>

  <h2>Détail des charges par catégorie</h2>
  <table>
    <thead><tr><th>Catégorie</th><th class="text-end">Montant</th></tr></thead>
    <tbody>
      <tr><td>Carburant</td><td class="text-end">{{ number_format($data['summary']['expenses']['fuel'], 0, '', ' ') }} FCFA</td></tr>
      <tr><td>Lavage & Nettoyage</td><td class="text-end">{{ number_format($data['summary']['expenses']['washing'], 0, '', ' ') }} FCFA</td></tr>
      <tr><td>Entretien & Pannes</td><td class="text-end">{{ number_format($data['summary']['expenses']['repair'], 0, '', ' ') }} FCFA</td></tr>
      <tr><td>Assurances</td><td class="text-end">{{ number_format($data['summary']['expenses']['insurance'], 0, '', ' ') }} FCFA</td></tr>
      <tr><td>Salaires chauffeurs</td><td class="text-end">{{ number_format($data['summary']['expenses']['salary'], 0, '', ' ') }} FCFA</td></tr>
      <tr><td>Autres charges</td><td class="text-end">{{ number_format($data['summary']['expenses']['other'], 0, '', ' ') }} FCFA</td></tr>
    </tbody>
  </table>

  <h2>Rentabilité par véhicule</h2>
  <table>
    <thead>
      <tr><th>Véhicule</th><th>Immat.</th><th>Élèves</th><th class="text-end">Recettes</th><th class="text-end">Charges</th><th class="text-end">Solde net</th></tr>
    </thead>
    <tbody>
      @forelse($data['vehicles'] as $v)
        <tr>
          <td>{{ $v['name'] }}</td>
          <td>{{ $v['registration'] }}</td>
          <td>{{ $v['students_count'] }}</td>
          <td class="text-end">{{ number_format($v['revenue'], 0, '', ' ') }} FCFA</td>
          <td class="text-end">{{ number_format($v['expenses'], 0, '', ' ') }} FCFA</td>
          <td class="text-end"><strong>{{ number_format($v['net'], 0, '', ' ') }} FCFA</strong></td>
        </tr>
      @empty
        <tr><td colspan="6">Aucun véhicule enregistré.</td></tr>
      @endforelse
    </tbody>
  </table>

  <h2>Taux de recouvrement (état actuel)</h2>
  <table>
    <tr><td>Abonnements soldés</td><td class="text-end">{{ $data['recovery']['paid_count'] }}</td></tr>
    <tr><td>Abonnements en avance / partiels</td><td class="text-end">{{ $data['recovery']['partial_count'] }}</td></tr>
    <tr><td>Abonnements impayés</td><td class="text-end">{{ $data['recovery']['unpaid_count'] }}</td></tr>
    <tr><td>Total dû (tous élèves confondus)</td><td class="text-end">{{ number_format($data['recovery']['total_due'], 0, '', ' ') }} FCFA</td></tr>
    <tr><td>Total déjà payé</td><td class="text-end">{{ number_format($data['recovery']['total_paid'], 0, '', ' ') }} FCFA</td></tr>
    <tr><td>Reste à recouvrer</td><td class="text-end"><strong>{{ number_format($data['recovery']['total_remaining'], 0, '', ' ') }} FCFA</strong></td></tr>
  </table>

  <h2>Évolution sur 6 mois</h2>
  <table>
    <thead><tr><th>Mois</th><th class="text-end">Recettes</th><th class="text-end">Charges</th><th class="text-end">Solde</th></tr></thead>
    <tbody>
      @foreach($data['monthly_trend'] as $m)
        <tr>
          <td>{{ $m['label'] }}</td>
          <td class="text-end">{{ number_format($m['receipts'], 0, '', ' ') }} FCFA</td>
          <td class="text-end">{{ number_format($m['expenses'], 0, '', ' ') }} FCFA</td>
          <td class="text-end">{{ number_format($m['receipts'] - $m['expenses'], 0, '', ' ') }} FCFA</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <h2>Liste des impayés ({{ count($data['unpaid_list']) }} élève(s))</h2>
  <table>
    <thead><tr><th>Élève</th><th>Classe</th><th>Ligne</th><th class="text-end">Total</th><th class="text-end">Payé</th><th class="text-end">Reste</th></tr></thead>
    <tbody>
      @forelse($data['unpaid_list'] as $u)
        <tr>
          <td>{{ $u['student_name'] }} <span style="color:#BBA98A;">({{ $u['matricule'] }})</span></td>
          <td>{{ $u['class_name'] }}</td>
          <td>{{ $u['route_name'] }}</td>
          <td class="text-end">{{ number_format($u['total_amount'], 0, '', ' ') }} FCFA</td>
          <td class="text-end">{{ number_format($u['amount_paid'], 0, '', ' ') }} FCFA</td>
          <td class="text-end"><strong>{{ number_format($u['remaining'], 0, '', ' ') }} FCFA</strong></td>
        </tr>
      @empty
        <tr><td colspan="6">Aucun impayé — tous les élèves sont à jour. 🎉</td></tr>
      @endforelse
    </tbody>
  </table>

  <p style="margin-top:24px; font-size:9px; color:#BBA98A; text-align:center;">
    Document généré via DexSchool Manager — {{ now()->format('d/m/Y H:i') }}
  </p>

</body>
</html>