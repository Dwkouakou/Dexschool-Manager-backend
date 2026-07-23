<?php

namespace App\Http\Controllers;

use App\Models\Establishment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EstablishmentSettingsController extends Controller
{
    /**
     * Renvoie l'identité complète de l'établissement de l'utilisateur connecté.
     * GET /api/settings/establishment
     */
    public function show(Request $request)
    {
        $establishment = Establishment::findOrFail(current_establishment_id());

        return response()->json([
            'status'        => 'success',
            'establishment' => [
                'id'             => $establishment->id,
                'name'           => $establishment->name,
                'code'           => $establishment->code,
                'sigle'          => $establishment->sigle,
                'phone'          => $establishment->phone,
                'email'          => $establishment->email,
                'website'        => $establishment->website,
                'address'        => $establishment->address,
                'director_name'  => $establishment->director_name,
                'founded_date'   => $establishment->founded_date,
                'motto'          => $establishment->motto,
                'logo'           => $establishment->logo,
            ],
        ]);
    }

    /**
     * Met à jour l'identité de l'établissement (nom, contacts, logo...).
     * Le CODE établissement n'est volontairement PAS modifiable ici — il sert
     * de référence stable (préfixes de matricules, connexion) et ne doit
     * changer que via une procédure dédiée côté SuperAdmin si nécessaire.
     * POST /api/settings/establishment
     */
    public function update(Request $request)
{
    $establishment = Establishment::findOrFail(current_establishment_id());

    $validated = $request->validate([
        'name'           => ['required', 'string', 'max:255'],
        'sigle'          => ['nullable', 'string', 'max:20'],
        'phone'          => ['nullable', 'string', 'max:25'],
        'email'          => ['nullable', 'email', 'max:150'],
        'website'        => ['nullable', 'string', 'max:150'],
        'address'        => ['nullable', 'string', 'max:255'],
        'director_name'  => ['nullable', 'string', 'max:150'],
        'founded_date'   => ['nullable', 'date'],
        'motto'          => ['nullable', 'string', 'max:255'],
        'logo'           => ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:2048'],
    ]);

    if ($request->hasFile('logo')) {
        if ($establishment->logo) {
            $oldPath = str_replace('/storage/', '', $establishment->logo);
            if (Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
        }
        $path = $request->file('logo')->store('establishments/logos', 'public');
        $validated['logo'] = '/storage/' . $path;
    }

    // ─── Assignation DIRECTE, sans dépendre de $fillable ───
    // Évite le piège classique : si ces colonnes ne sont pas listées dans
    // $fillable sur le modèle Establishment, update($validated) les
    // ignorerait silencieusement (aucune erreur, mais rien n'est écrit).
    foreach ($validated as $key => $value) {
        $establishment->{$key} = $value;
    }
    $establishment->save();

    return response()->json([
        'status'        => 'success',
        'message'       => 'Identité de l\'établissement mise à jour avec succès.',
        'establishment' => $establishment->fresh(),
    ]);
}
}