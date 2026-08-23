<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Reçu de versement cantine {{ $payment->receipt_number }}</title>
    <style>
        @page { margin: 18px; }
        body {
            font-family: sans-serif;
            font-size: 11px;
            color: #221911;
            margin: 0;
            padding: 0;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #3D2E22;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }
        .school-name {
            font-size: 16px;
            font-weight: bold;
            color: #3D2E22;
            margin: 0;
        }
        .school-sub {
            font-size: 9px;
            color: #666666;
            margin: 2px 0 0 0;
        }
        .receipt-title {
            text-align: center;
            font-size: 13px;
            font-weight: bold;
            color: #E67E22;
            text-transform: uppercase;
            margin: 10px 0;
            letter-spacing: 1px;
        }
        .receipt-number {
            text-align: center;
            font-family: monospace;
            font-size: 11px;
            background-color: #FAF7F5;
            border: 1px solid #EEE5DE;
            padding: 5px;
            margin-bottom: 12px;
        }
        table.info {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        table.info td {
            padding: 4px 0;
            font-size: 11px;
            vertical-align: top;
        }
        table.info td.label {
            color: #666666;
            width: 40%;
        }
        table.info td.value {
            font-weight: bold;
            color: #221911;
        }
        .amount-box {
            text-align: center;
            background-color: #FAF7F5;
            border: 1px solid #EEE5DE;
            border-radius: 6px;
            padding: 12px;
            margin: 14px 0;
        }
        .amount-label {
            font-size: 10px;
            color: #666666;
            text-transform: uppercase;
        }
        .amount-value {
            font-size: 20px;
            font-weight: bold;
            color: #27AE60;
            margin-top: 4px;
        }
        .footer {
            margin-top: 20px;
            padding-top: 8px;
            border-top: 1px solid #EEE5DE;
            font-size: 9px;
            color: #999999;
            text-align: center;
        }
        .signature-row {
            margin-top: 30px;
            width: 100%;
        }
        .signature-box {
            width: 45%;
            display: inline-block;
            text-align: center;
            font-size: 9px;
            color: #666666;
        }
        .signature-line {
            border-top: 1px solid #999999;
            margin-top: 25px;
            padding-top: 3px;
        }
    </style>
</head>
<body>

    <div class="header">
        <p class="school-name">{{ $establishment->name ?? 'Groupe Scolaire Mariam Fofana' }}</p>
        <p class="school-sub">{{ $establishment->address ?? '' }} @if($establishment->phone ?? null) — Tél: {{ $establishment->phone }} @endif</p>
    </div>

    <div class="receipt-title">Reçu de versement — Cantine Scolaire</div>
    <div class="receipt-number">N&deg; {{ $payment->receipt_number }}</div>

    <table class="info">
        <tr>
            <td class="label">Date d'émission</td>
            <td class="value">{{ $generated_at->format('d/m/Y à H:i') }}</td>
        </tr>
        <tr>
            <td class="label">Date du versement</td>
            <td class="value">{{ \Carbon\Carbon::parse($payment->payment_date)->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td class="label">Type d'opération</td>
            <td class="value">{{ $payment->type === 'renewal' ? 'Renouvellement d\'abonnement' : 'Versement de scolarité cantine' }}</td>
        </tr>
        <tr>
            <td class="label">Période couverte</td>
            <td class="value">
                @if($payment->period_covered && $payment->period_end)
                    Du {{ $payment->period_covered->format('d/m/Y') }} au {{ $payment->period_end->format('d/m/Y') }}
                @else
                    Non renseignée
                @endif
            </td>
        </tr>
        <tr>
            <td class="label">Élève</td>
            <td class="value">{{ $student ? ($student->last_name . ' ' . $student->first_name) : 'N/A' }}</td>
        </tr>
        <tr>
            <td class="label">Matricule</td>
            <td class="value">{{ $student->matricule ?? 'N/A' }}</td>
        </tr>
        <tr>
            <td class="label">Classe</td>
            <td class="value">{{ ($student && $student->classe) ? $student->classe->name : 'N/A' }}</td>
        </tr>
        <tr>
            <td class="label">Forfait repas</td>
            <td class="value">{{ $subscription && $subscription->mealType ? $subscription->mealType->name : 'N/A' }}</td>
        </tr>
        <tr>
            <td class="label">Tarif mensuel du forfait</td>
            <td class="value">{{ ($subscription && $subscription->mealType) ? number_format($subscription->mealType->price_per_month, 0, '', ' ') . ' FCFA' : 'N/A' }}</td>
        </tr>
        <tr>
            <td class="label">Caissier / Agent</td>
            <td class="value">{{ $payment->collector->name ?? 'Système' }}</td>
        </tr>
    </table>

    <div class="amount-box">
        <div class="amount-label">Montant encaissé</div>
        <div class="amount-value">{{ number_format($payment->amount, 0, '', ' ') }} FCFA</div>
    </div>

    @if(!is_null($payment->remaining_after))
        @if($payment->remaining_after <= 0)
            <div class="amount-box" style="background-color: #EAF7EF; border-color: #A7F3D0;">
                <div class="amount-label">Statut du compte</div>
                <div class="amount-value" style="color: #065F46;">SOLDÉ</div>
            </div>
        @else
            <div class="amount-box" style="background-color: #FDE8F0; border-color: #F5C6D0;">
                <div class="amount-label">Reste à payer après ce versement</div>
                <div class="amount-value" style="color: #C0395A;">{{ number_format($payment->remaining_after, 0, '', ' ') }} FCFA</div>
            </div>
        @endif
    @endif

    <div class="signature-row">
        <div class="signature-box">
            <div class="signature-line">Signature du caissier</div>
        </div>
        <div class="signature-box" style="float: right;">
            <div class="signature-line">Cachet de l'établissement</div>
        </div>
    </div>

    <div class="footer">
        Document généré automatiquement par DexSchool Manager — Ce reçu fait foi de paiement pour la période mentionnée.
    </div>

</body>
</html>