<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
  @page { margin: 0; }
  body {
    margin: 0; padding: 24px;
    font-family: 'Helvetica', Arial, sans-serif;
    background: #FBF8F4;
  }
  .card {
    width: 320px;
    margin: 0 auto;
    border-radius: 18px;
    overflow: hidden;
    background: #FFFFFF;
    border: 1px solid #E5D9CC;
  }
  .header {
    background: #3D2E22;
    color: #ffffff;
    padding: 16px 20px;
  }
  .header .school {
    font-size: 15px;
    font-weight: bold;
    margin: 0 0 2px;
  }
  .header .tag {
    font-size: 9px;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: #E8DDD0;
    margin: 0;
  }
  .bus-band {
    background: #FDDCBC;
    text-align: center;
    padding: 10px 0;
    font-size: 22px;
  }
  .body {
    padding: 16px 20px;
  }
  .row {
    display: table;
    width: 100%;
  }
  .label {
    font-size: 9px;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: #8C7B6B;
    font-weight: bold;
    margin-bottom: 2px;
  }
  .name {
    font-size: 18px;
    font-weight: bold;
    color: #221911;
    text-transform: uppercase;
    margin: 0;
  }
  .firstname {
    font-size: 14px;
    font-weight: 600;
    color: #3D2E22;
    margin: 0 0 6px;
  }
  .matricule {
    display: inline-block;
    background: #FEF0E6;
    color: #C0571A;
    font-size: 11px;
    font-weight: bold;
    padding: 3px 10px;
    border-radius: 6px;
  }
  .route-box {
    background: #FEF0E6;
    border: 1px solid #F5DCC8;
    border-radius: 10px;
    padding: 12px 14px;
    margin-top: 14px;
  }
  .route-box .route-name {
    font-size: 14px;
    font-weight: bold;
    color: #221911;
  }
  .footer {
    border-top: 1px solid #EEE5DE;
    padding: 14px 20px 18px;
  }
  .status-badge {
    display: inline-block;
    margin-top: 6px;
    padding: 4px 10px;
    border-radius: 20px;
    color: #fff;
    font-size: 9px;
    font-weight: bold;
  }
  .status-active { background: #27AE60; }
  .status-blocked { background: #C0395A; }
  .footnote {
    text-align: center;
    margin-top: 18px;
    font-size: 9px;
    color: #BBA98A;
  }
</style>
</head>
<body>

  <div class="card">
    <div class="header">
      <p class="school">{{ $establishmentName }}</p>
      <p class="tag">Carte de Transport Scolaire</p>
    </div>

    <div class="bus-band">
      <svg width="90" height="36" viewBox="0 0 90 36" xmlns="http://www.w3.org/2000/svg">
        <rect x="6" y="8" width="70" height="18" rx="4" fill="#E67E22"/>
        <rect x="6" y="8" width="70" height="5" rx="2" fill="#F39C12"/>
        <rect x="6" y="21" width="70" height="5" fill="#C0571A"/>
        <rect x="14" y="12" width="8" height="6" fill="#FFF9F0"/>
        <rect x="26" y="12" width="8" height="6" fill="#FFF9F0"/>
        <rect x="38" y="12" width="8" height="6" fill="#FFF9F0"/>
        <rect x="50" y="12" width="8" height="6" fill="#FFF9F0"/>
        <rect x="62" y="12" width="8" height="6" fill="#FFF9F0"/>
        <circle cx="22" cy="28" r="5" fill="#3D2E22"/>
        <circle cx="60" cy="28" r="5" fill="#3D2E22"/>
      </svg>
    </div>

    <div class="body">
      <p class="label">Titulaire</p>
      <p class="name">{{ $subscription->student->last_name ?? '' }}</p>
      <p class="firstname">{{ $subscription->student->first_name ?? '' }}</p>
      <span class="matricule">{{ $subscription->student->matricule ?? '—' }}</span>

      <div class="route-box">
        <p class="label" style="margin-bottom:4px;">Circuit assigné</p>
        <p class="route-name">{{ $subscription->route->name ?? 'Non assigné' }}</p>
      </div>
    </div>

    <div class="footer">
      <p class="label">Valable jusqu'au</p>
      <p style="font-size:14px; font-weight:bold; color:#221911; margin:0;">
        {{ $subscription->end_date ? \Carbon\Carbon::parse($subscription->end_date)->format('d/m/Y') : '—' }}
      </p>
      <span class="status-badge {{ $subscription->status === 'active' ? 'status-active' : 'status-blocked' }}">
        {{ $subscription->status === 'active' ? 'ACCÈS AUTORISÉ' : 'ACCÈS BLOQUÉ' }}
      </span>
    </div>
  </div>

  <p class="footnote">Document généré via DexSchool Manager — {{ now()->format('d/m/Y H:i') }}</p>

</body>
</html>