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
  .meal-band {
    background: #FDDCBC;
    text-align: center;
    padding: 10px 0;
    font-size: 22px;
  }
  .body {
    padding: 16px 20px;
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
  .meal-box {
    background: #FEF0E6;
    border: 1px solid #F5DCC8;
    border-radius: 10px;
    padding: 12px 14px;
    margin-top: 14px;
  }
  .meal-box .meal-name {
    font-size: 14px;
    font-weight: bold;
    color: #221911;
  }
  .class-badge {
    display: inline-block;
    background: #E67E22;
    color: #fff;
    font-size: 9.5px;
    font-weight: bold;
    padding: 3px 10px;
    border-radius: 20px;
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
  .status-paid { background: #27AE60; }
  .status-partial { background: #E67E22; }
  .status-unpaid { background: #C0395A; }
  .remaining-note {
    font-size: 10px;
    color: #C0395A;
    margin-top: 4px;
  }
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
      <p class="tag">Carte de Cantine Scolaire</p>
    </div>

    <div class="meal-band">
      <svg width="60" height="36" viewBox="0 0 60 36" xmlns="http://www.w3.org/2000/svg">
        <!-- Assiette -->
        <circle cx="30" cy="18" r="14" fill="#FFFFFF" stroke="#E67E22" stroke-width="2"/>
        <circle cx="30" cy="18" r="8" fill="none" stroke="#E67E22" stroke-width="1.2"/>
        <!-- Fourchette -->
        <rect x="6" y="6" width="2.4" height="24" fill="#3D2E22"/>
        <rect x="3" y="6" width="2" height="8" fill="#3D2E22"/>
        <rect x="6.4" y="6" width="2" height="8" fill="#3D2E22"/>
        <rect x="9.8" y="6" width="2" height="8" fill="#3D2E22"/>
        <!-- Couteau -->
        <path d="M52 6 L52 30 L54.4 30 L54.4 18 C54.4 12 52.8 7 52 6 Z" fill="#3D2E22"/>
      </svg>
    </div>

    <div class="body">
      <p class="label">Titulaire</p>
      <p class="name">{{ $student->last_name ?? '' }}</p>
      <p class="firstname">{{ $student->first_name ?? '' }}</p>
      <span class="matricule">{{ $student->matricule ?? '—' }}</span>

      <div class="meal-box">
        <div style="display: table; width: 100%; margin-bottom: 4px;">
          <div style="display: table-cell;">
            <p class="label" style="margin-bottom:0;">Forfait souscrit</p>
          </div>
          <div style="display: table-cell; text-align: right;">
            <span class="class-badge">{{ ($student && $student->classe) ? $student->classe->name : '—' }}</span>
          </div>
        </div>
        <p class="meal-name">{{ $subscription->mealType->name ?? 'Non assigné' }}</p>
      </div>
    </div>

    <div class="footer">
      <p class="label">Valable jusqu'au</p>
      <p style="font-size:14px; font-weight:bold; color:#221911; margin:0;">
        {{ $subscription->end_date ? \Carbon\Carbon::parse($subscription->end_date)->format('d/m/Y') : '—' }}
      </p>

      @php
        $statusClass = $subscription->payment_status === 'paid' ? 'status-paid' : ($subscription->payment_status === 'partial' ? 'status-partial' : 'status-unpaid');
        $statusLabel = $subscription->payment_status === 'paid' ? 'SOLDÉ' : ($subscription->payment_status === 'partial' ? 'AVANCE' : 'IMPAYÉ');
      @endphp
      <span class="status-badge {{ $statusClass }}">{{ $statusLabel }}</span>

      @if($remaining > 0)
        <p class="remaining-note">Reste à payer : {{ number_format($remaining, 0, '', ' ') }} FCFA</p>
      @endif
    </div>
  </div>

  <p class="footnote">Document généré via DexSchool Manager — {{ now()->format('d/m/Y H:i') }}</p>

</body>
</html>