<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Feuille d'appel — Cantine</title>
    <style>
        @page { margin: 20px; }
        body { font-family: sans-serif; font-size: 11px; color: #221911; margin: 0; }
        .header { text-align: center; border-bottom: 2px solid #3D2E22; padding-bottom: 10px; margin-bottom: 14px; }
        .school-name { font-size: 16px; font-weight: bold; color: #3D2E22; margin: 0; }
        .title { text-align: center; font-size: 14px; font-weight: bold; color: #E67E22; text-transform: uppercase; margin: 10px 0; letter-spacing: 1px; }
        .meta-box { background: #FAF7F5; border: 1px solid #EEE5DE; border-radius: 6px; padding: 10px 14px; margin-bottom: 14px; font-size: 10.5px; }
        .meta-box strong { color: #3D2E22; }
        table { width: 100%; border-collapse: collapse; }
        th { background-color: #FAF7F5; color: #3D2E22; font-size: 10px; text-align: left; padding: 6px 8px; border-bottom: 2px solid #EEE5DE; }
        td { font-size: 10.5px; padding: 5px 8px; border-bottom: 1px solid #F5F0EC; }
        .text-center { text-align: center; }
        .status-present { color: #27AE60; font-weight: bold; }
        .status-absent { color: #C0395A; font-weight: bold; }
        .footer { margin-top: 20px; padding-top: 8px; border-top: 1px solid #EEE5DE; font-size: 9px; color: #999999; text-align: center; }
    </style>
</head>
<body>

    <div class="header">
        <p class="school-name">{{ $establishment->name ?? 'Groupe Scolaire' }}</p>
    </div>

    <div class="title">Feuille d'Appel — Réfectoire</div>

    <div class="meta-box">
        <strong>Date de l'appel :</strong> {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}<br>
        <strong>Enregistré et verrouillé par :</strong> {{ $sheet->submitter->name ?? 'Système' }}<br>
        <strong>Le :</strong> {{ $sheet->submitted_at->format('d/m/Y à H:i') }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Matricule</th>
                <th>Élève</th>
                <th>Classe</th>
                <th>Forfait</th>
                <th class="text-center">Statut</th>
            </tr>
        </thead>
        <tbody>
            @forelse($attendances as $att)
                @php $sub = $att->subscription; $student = $sub->student ?? null; @endphp
                <tr>
                    <td>{{ $student->matricule ?? 'N/A' }}</td>
                    <td>{{ $student ? ($student->last_name . ' ' . $student->first_name) : 'N/A' }}</td>
                    <td>{{ ($student && $student->classe) ? $student->classe->name : 'N/A' }}</td>
                    <td>{{ $sub->mealType->name ?? 'N/A' }}</td>
                    <td class="text-center {{ $att->present ? 'status-present' : 'status-absent' }}">
                        {{ $att->present ? 'PRÉSENT' : 'ABSENT' }}
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center">Aucun élève pointé ce jour.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Document généré automatiquement par DexSchool Manager — Feuille d'appel verrouillée, non modifiable.
    </div>

</body>
</html>