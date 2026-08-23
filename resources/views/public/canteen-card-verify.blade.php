<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vérification Carte Cantine</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #FAF7F5;
            margin: 0;
            padding: 20px 16px;
            color: #221911;
        }
        .container { max-width: 420px; margin: 0 auto; }
        .header { text-align: center; margin-bottom: 20px; }
        .school-name { font-size: 15px; font-weight: bold; color: #3D2E22; }

        .status-banner {
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
            color: #fff;
        }
        .status-icon { font-size: 40px; margin-bottom: 8px; }
        .status-title { font-size: 18px; font-weight: bold; }
        .status-sub { font-size: 13px; opacity: 0.9; margin-top: 4px; }

        .card {
            background: #fff;
            border-radius: 16px;
            padding: 18px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 14px;
        }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #F5F0EC; font-size: 14px; }
        .row:last-child { border-bottom: none; }
        .label { color: #8C7B6B; }
        .value { font-weight: 600; color: #221911; text-align: right; }

        .remaining-box {
            background: #FDE8F0;
            border: 1px solid #F5C6D0;
            border-radius: 12px;
            padding: 14px;
            text-align: center;
            margin-bottom: 14px;
        }
        .remaining-label { font-size: 11px; color: #A93226; text-transform: uppercase; }
        .remaining-value { font-size: 20px; font-weight: bold; color: #C0395A; margin-top: 4px; }

        .footer { text-align: center; font-size: 11px; color: #BBA98A; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="school-name">Vérification — Cantine Scolaire</div>
        </div>

        @php
            // Détermine la couleur/statut global affiché en priorité
            if ($isSuspended || $isExpired) {
                $bannerColor = '#7F8C8D';
                $icon = '&#9888;'; // ⚠
                $title = $isExpired ? 'Abonnement expiré' : 'Abonnement suspendu';
                $sub = $isExpired ? 'La période couverte est terminée.' : 'Statut actuellement inactif.';
            } elseif ($subscription->payment_status === 'unpaid') {
                $bannerColor = '#C0395A';
                $icon = '&#10060;'; // ❌
                $title = 'Impayé';
                $sub = 'Aucun versement enregistré sur cette période.';
            } elseif ($subscription->payment_status === 'partial') {
                $bannerColor = '#E67E22';
                $icon = '&#9203;'; // ⏳
                $title = 'Avance partielle';
                $sub = 'Un reste à payer est encore dû.';
            } else {
                $bannerColor = '#27AE60';
                $icon = '&#9989;'; // ✅
                $title = 'Actif et à jour';
                $sub = 'Abonnement valide et soldé.';
            }
        @endphp

        <div class="status-banner" style="background-color: {{ $bannerColor }};">
            <div class="status-icon">{!! $icon !!}</div>
            <div class="status-title">{{ $title }}</div>
            <div class="status-sub">{{ $sub }}</div>
        </div>

        <div class="card">
            <div class="row">
                <span class="label">Élève</span>
                <span class="value">{{ $student->last_name ?? '' }} {{ $student->first_name ?? '' }}</span>
            </div>
            <div class="row">
                <span class="label">Matricule</span>
                <span class="value">{{ $student->matricule ?? 'N/A' }}</span>
            </div>
            <div class="row">
                <span class="label">Classe</span>
                <span class="value">{{ ($student && $student->classe) ? $student->classe->name : 'N/A' }}</span>
            </div>
            <div class="row">
                <span class="label">Forfait</span>
                <span class="value">{{ $subscription->mealType->name ?? 'N/A' }}</span>
            </div>
            <div class="row">
                <span class="label">Période couverte</span>
                <span class="value">
                    {{ $subscription->start_date ? $subscription->start_date->format('d/m/Y') : '—' }}
                    au {{ $subscription->end_date ? $subscription->end_date->format('d/m/Y') : '—' }}
                </span>
            </div>
        </div>

        @if($remaining > 0)
            <div class="remaining-box">
                <div class="remaining-label">Reste à payer</div>
                <div class="remaining-value">{{ number_format($remaining, 0, '', ' ') }} FCFA</div>
            </div>
        @endif

        <div class="footer">
            Vérifié le {{ $checkedAt->format('d/m/Y à H:i') }} — DexSchool Manager
        </div>
    </div>
</body>
</html>