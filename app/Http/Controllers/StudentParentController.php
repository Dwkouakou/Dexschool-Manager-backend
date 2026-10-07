<?php

namespace App\Http\Controllers;

use App\Models\Academic\StudentParent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StudentParentController extends Controller
{
    //
    public function store(Request $request)
{
    assert_writable_year();
    $validated = $request->validate([
        'student_id'           => 'required|exists:students,id',
        'type'                 => 'required|string|max:50',
        'last_name'            => 'required|string|max:255',
        'first_name'           => 'required|string|max:255',
        'phone'                => 'required|string|max:20',
        'phone_alt'            => 'nullable|string|max:20',
        'email'                => 'nullable|email|max:255',
        'profession'           => 'nullable|string|max:255',
        'employer'             => 'nullable|string|max:255',
        'district'             => 'nullable|string|max:255',
        'address'              => 'nullable|string|max:255',
        'photo'                => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        'is_emergency_contact' => 'required|in:0,1,true,false',
        'is_main_contact'      => 'required|in:0,1,true,false',
    ]);

    // Sécurité multi-tenant : l'élève doit appartenir à l'établissement courant
    // (Student a le trait → find() ne renvoie que les élèves de cet établissement)
    $student = \App\Models\Academic\Student::find($validated['student_id']);
    if (!$student) {
        return response()->json([
            'status'  => 'error',
            'message' => "Cet élève n'appartient pas à votre établissement."
        ], 404);
    }

    try {
        return DB::transaction(function () use ($request, $validated) {

            if ($request->hasFile('photo') && $request->file('photo')->isValid()) {
                $path = $request->file('photo')->store('parents_photos', 'public');
                $validated['photo'] = $path;
            }

            $validated['is_emergency_contact'] = filter_var($validated['is_emergency_contact'], FILTER_VALIDATE_BOOLEAN);
            $validated['is_main_contact']      = filter_var($validated['is_main_contact'], FILTER_VALIDATE_BOOLEAN);

            $parent = StudentParent::create($validated);

            if ($validated['is_main_contact']) {
                StudentParent::where('student_id', $validated['student_id'])
                    ->where('id', '!=', $parent->id)
                    ->update(['is_main_contact' => false]);
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Parent ajouté avec succès !',
                'data'    => $parent
            ], 201);
        });

    } catch (\Exception $e) {
        if (isset($path)) {
            Storage::disk('public')->delete($path);
        }

        return response()->json([
            'status'  => 'error',
            'message' => "Une erreur est survenue lors de l'enregistrement.",
            'error'   => $e->getMessage()
        ], 500);
    }
}

    /**
     * Modifie un parent / tuteur existant.
     * PUT|POST /student-parents/{id}  (le front envoie POST + _method=PUT à cause de la photo)
     */
    public function update(Request $request, string $id)
    {
        assert_writable_year();

        // Sécurité multi-tenant : Student porte le trait d'établissement,
        // donc whereHas('student') exclut les parents des autres établissements.
        $parent = StudentParent::whereHas('student')->find($id);
        if (!$parent) {
            return response()->json([
                'status'  => 'error',
                'message' => "Parent introuvable dans votre établissement."
            ], 404);
        }

        $validated = $request->validate([
            'type'                 => 'required|string|max:50',
            'last_name'            => 'required|string|max:255',
            'first_name'           => 'required|string|max:255',
            'phone'                => 'required|string|max:20',
            'phone_alt'            => 'nullable|string|max:20',
            'email'                => 'nullable|email|max:255',
            'profession'           => 'nullable|string|max:255',
            'employer'             => 'nullable|string|max:255',
            'district'             => 'nullable|string|max:255',
            'address'              => 'nullable|string|max:255',
            'photo'                => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'is_emergency_contact' => 'required|in:0,1,true,false',
            'is_main_contact'      => 'required|in:0,1,true,false',
        ]);

        $newPath = null;

        try {
            return DB::transaction(function () use ($request, $parent, $validated, &$newPath) {

                $oldPhoto = $parent->photo;

                // Photo : on ne touche à l'existante que si une nouvelle est envoyée
                unset($validated['photo']);
                if ($request->hasFile('photo') && $request->file('photo')->isValid()) {
                    $newPath = $request->file('photo')->store('parents_photos', 'public');
                    $validated['photo'] = $newPath;
                }

                $validated['is_emergency_contact'] = filter_var($validated['is_emergency_contact'], FILTER_VALIDATE_BOOLEAN);
                $validated['is_main_contact']      = filter_var($validated['is_main_contact'], FILTER_VALIDATE_BOOLEAN);

                $parent->update($validated);

                // Un seul contact principal par élève
                if ($validated['is_main_contact']) {
                    StudentParent::where('student_id', $parent->student_id)
                        ->where('id', '!=', $parent->id)
                        ->update(['is_main_contact' => false]);
                }

                // Ancienne photo supprimée seulement une fois la nouvelle bien enregistrée
                if ($newPath && $oldPhoto) {
                    Storage::disk('public')->delete($oldPhoto);
                }

                return response()->json([
                    'status'  => 'success',
                    'message' => 'Parent modifié avec succès !',
                    'data'    => $parent->fresh()
                ], 200);
            });

        } catch (\Exception $e) {
            if ($newPath) {
                Storage::disk('public')->delete($newPath);
            }

            return response()->json([
                'status'  => 'error',
                'message' => "Une erreur est survenue lors de la modification.",
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}