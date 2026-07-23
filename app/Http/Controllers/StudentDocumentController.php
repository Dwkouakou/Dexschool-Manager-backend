<?php

namespace App\Http\Controllers;

use App\Models\Academic\Student;
use App\Models\Academic\StudentDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class StudentDocumentController extends Controller
{
    //

     public function store(Request $request, string $student_id)
    {
        assert_writable_year();
        // 1. Validation stricte des données reçues
        $validated = $request->validate([
            'document_type' => [
                'required', 
                'string', 
                Rule::in(['acte_naissance', 'certificat_medical', 'photo_identite', 'bulletin',  'certificat_national_identite', 'certificat_scolarite', 'piece_parent', 'autre'])
            ],
            'title'         => ['required', 'string', 'max:255'],
            'file'          => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'], // 5 Mo max
            'description'   => ['nullable', 'string']
        ]);

        
        $student = Student::findOrFail($student_id);
        try {
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                
                $folder = 'documents/student_' . $student_id;
                $path = $file->store($folder, 'public');
                
                $document = StudentDocument::create([
                    'student_id'    => $student_id,
                    'document_type' => $validated['document_type'],
                    'title'         => $validated['title'],
                    'file_path'     => $path,
                    'file_name'     => $file->getClientOriginalName(),
                    'mime_type'     => $file->getClientMimeType(),
                    'file_size'     => $file->getSize(),
                    'description'   => $validated['description'] ?? null,
                ]);

                return response()->json([
                    'status'   => 'success',
                    'message'  => 'Document ajouté avec succès.',
                    'document' => $document
                ], 201);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors du traitement du fichier.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $document = StudentDocument::findOrFail($id);

            if ($document->file_path && Storage::disk('public')->exists($document->file_path)) {
                Storage::disk('public')->delete($document->file_path);
            }

            $document->delete();

            return response()->json([
                'status'  => 'success',
                'message' => 'Document supprimé avec succès.'
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Document introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la suppression.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }
}
