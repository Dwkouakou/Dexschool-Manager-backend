<?php

namespace App\Http\Controllers;

use App\Models\Academic\AcademicYears;
use App\Models\Academic\Student;
use App\Models\Library\Book;
use App\Models\Library\BookCategory;
use App\Models\Library\BookCopy;
use App\Models\Library\BookLoan;
use App\Models\Personel\Employee;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LibraryController extends Controller
{
    //
    /**
     * Récupère les métriques globales et les alertes de retard de la bibliothèque.
     * URL : GET /api/library/dashboard
     */
    public function dashboardMetrics()
    {
        try {
            $today = Carbon::today()->format('Y-m-d');

            // 1. Nombre total d'œuvres / titres uniques enregistrés au catalogue
            $totalBooks = Book::count();

            // 2. Nombre total d'exemplaires physiques possédés par l'école
            $totalCopies = BookCopy::count();

            // 3. Emprunts actuellement en cours (Livres dehors)
            $activeLoans = BookLoan::where('status', 'borrowed')->count();

            // 4. ALERTE CRITIQUE : Calcul automatique et rigoureux des retards de restitution
            // Filtre les prêts non rendus dont la date attendue est strictement inférieure à la date d'aujourd'hui
            $lateLoansCount = BookLoan::where('status', 'borrowed')
                ->where('expected_return_date', '<', $today)
                ->count();

            // 5. Stock de livres physiquement disponibles en rayon
            $availableCopiesCount = BookCopy::where('status', 'available')->count();

            // 6. Envoi de la réponse structurée lue par LibraryDashboardPage.jsx
            return response()->json([
                'total_books'            => (int) $totalBooks,
                'total_copies'           => (int) $totalCopies,
                'active_loans'           => (int) $activeLoans,
                'late_loans_count'       => (int) $lateLoansCount,
                'available_copies_count' => (int) $availableCopiesCount,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur technique lors du calcul des indicateurs de la bibliothèque.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupère le catalogue complet des rayons et catégories de livres.
     * URL : GET /api/book-categories
     */
    public function indexCategories()
    {
        try {
            // Chargement de toutes les catégories triées par ordre alphabétique
            // avec calcul intégré et optimisé du nombre de livres rattachés (books_count)
            $categories = BookCategory::withCount('books')
                ->orderBy('name', 'asc')
                ->get();

            // Reformatage propre des structures pour l'interface React
            $formatted = $categories->map(function ($cat) {
                return [
                    'id'          => $cat->id,
                    'name'        => $cat->name, // ex: Roman Africain
                    'description' => $cat->description, // Emplacement physique (ex: Rayon B)
                    'books_count' => (int) $cat->books_count, // Nombre de titres associés
                ];
            });

            return response()->json($formatted, 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur technique est survenue lors du chargement des catégories littéraires.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Enregistre une nouvelle catégorie littéraire dans le système.
     * URL : POST /api/book-categories
     */
    public function storeCategory(Request $request)
    {
        // 1. Validation stricte du nom de la catégorie
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:100', 'unique:book_categories,name'],
            'description' => ['nullable', 'string'],
        ]);

        try {
            // 2. Nettoyage de la saisie (première lettre en majuscule)
            $validated['name'] = ucfirst(trim($validated['name']));

            // 3. Enregistrement en base de données
            $category = BookCategory::create($validated);

            return response()->json([
                'status'   => 'success',
                'message'  => "La catégorie '{$category->name}' a été configurée avec succès.",
                'category' => $category
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur de validation. Ce nom de catégorie est peut-être déjà utilisé.',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur imprévue est survenue lors de la création de la catégorie.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Modifie l'intitulé ou l'emplacement d'une catégorie littéraire.
     * URL : PUT /api/book-categories/{id}
     */
    public function updateCategory(Request $request, string $id)
    {
        try {
            $category = BookCategory::findOrFail($id);

            // 1. Validation des modifications (S'assure que le nom reste unique sauf pour cette ligne)
            $validated = $request->validate([
                'name'        => ['required', 'string', 'max:100', Rule::unique('book_categories', 'name')->ignore($id)],
                'description' => ['nullable', 'string'],
            ]);

            // 2. Nettoyage et formatage sain
            $validated['name'] = ucfirst(trim($validated['name']));

            // 3. Application de la mise à jour en base de données
            $category->update($validated);

            return response()->json([
                'status'  => 'success',
                'message' => "La catégorie '{$category->name}' a été modifiée avec succès."
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Données incorrectes. Vérifiez que ce nom de catégorie n’existe pas déjà.',
                'errors'  => $e->errors()
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Catégorie littéraire introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur imprévue est survenue lors de la mise à jour.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supprime une catégorie littéraire si aucun livre n'y est adossé.
     * URL : DELETE /api/book-categories/{id}
     */
    public function destroyCategory(string $id)
    {
        try {
            // Récupère la catégorie en comptant le nombre d'œuvres liées
            $category = BookCategory::withCount('books')->findOrFail($id);

            // 1. VERROU DE SÉCURITÉ : Bloquer si des livres sont classés dans ce rayon
            if ($category->books_count > 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Impossible de supprimer ce rayon car " . $category->books_count . " livre(s) y sont actuellement classé(s). Veuillez d'abord réaffecter ces ouvrages à une autre thématique."
                ], 422);
            }

            // 2. Suppression physique en base si le rayon est totalement vide
            $category->delete();

            return response()->json([
                'status'  => 'success',
                'message' => "Le rayon thématique a été retiré du catalogue avec succès."
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Catégorie littéraire introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur technique est survenue lors de la suppression du rayon.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupère la liste complète de toutes les œuvres littéraires du catalogue.
     * URL : GET /api/books
     */
    public function indexBooks()
    {
        try {
            // Chargement du catalogue trié du livre le plus récent au plus ancien,
            // incluant sa thématique (relation category)
            $books = Book::with(['category'])
                ->latest()
                ->get();

            // Reformatage propre pour un mapping fluide côté React
            $formatted = $books->map(function ($book) {
                return [
                    'id'               => $book->id,
                    'category_id'      => $book->category_id,
                    'category_name'    => $book->category ? $book->category->name : 'Général',
                    'title'            => $book->title,
                    'author'           => $book->author,
                    'publisher'        => $book->publisher,
                    'isbn'             => $book->isbn,
                    'publication_year' => $book->publication_year,
                    'description'      => $book->description,
                    'cover'            => $book->cover, // Renvoie l'URL relative de l'image stockée
                    'is_active'        => (bool) $book->is_active,
                ];
            });

            return response()->json($formatted, 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur technique est survenue lors de la récupération du catalogue.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Enregistre une nouvelle œuvre littéraire avec son image de couverture.
     * URL : POST /api/books
     */
    public function storeBook(Request $request)
    {
        // 1. Validation stricte du catalogue et de l'image de couverture
        $validated = $request->validate([
            'category_id'      => ['required', 'exists:book_categories,id'],
            'title'            => ['required', 'string', 'max:150'],
            'author'           => ['nullable', 'string', 'max:100'],
            'publisher'        => ['nullable', 'string', 'max:100'],
            'isbn'             => ['nullable', 'string', 'max:30'],
            'publication_year' => ['nullable', 'integer', 'min:1800', 'max:' . date('Y')],
            'description'      => ['nullable', 'string'],
            'cover'            => ['nullable', 'file', 'mimes:jpeg,png,jpg,webp', 'max:2048'], // Max 2Mo
        ]);

        try {
            // 2. Traitement et stockage physique de la photo de couverture
            $coverPath = null;
            if ($request->hasFile('cover')) {
                $file = $request->file('cover');
                // Sauvegarde dans le dossier public storage/library/covers
                $coverPath = $file->store('library/covers', 'public');
            }

            // 3. Insertion propre du livre dans la base de données
            $book = Book::create([
                'category_id'      => (int) $validated['category_id'],
                'title'            => trim($validated['title']),
                'author'           => $validated['author'] ? trim($validated['author']) : null,
                'publisher'        => $validated['publisher'] ? trim($validated['publisher']) : null,
                'isbn'             => $validated['isbn'] ? strtoupper(trim($validated['isbn'])) : null,
                'publication_year' => $validated['publication_year'] ? (int) $validated['publication_year'] : null,
                'description'      => $validated['description'] ?? null,
                'cover'            => $coverPath ? '/storage/' . $coverPath : null, // Lien d'accès lu par React
                'is_active'        => true,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => "L'œuvre '{$book['title']}' a été indexée avec succès dans le catalogue général de l'école.",
                'book'    => $book
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur de validation. Veuillez vérifier les champs obligatoires fournis.',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur imprévue est survenue lors de l’indexation du livre.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Affiche la fiche descriptive d'un ouvrage avec l'état de l'ensemble de ses exemplaires.
     * URL : GET /api/books/{id}
     */
    public function showBook(string $id)
    {
        try {
            // Chargement du livre avec sa catégorie et l'ensemble de ses exemplaires physiques
            $book = Book::with(['category', 'copies'])->findOrFail($id);

            // Reformatage plat et structuré pour un affichage fluide côté React
            $formatted = [
                'id'               => $book->id,
                'title'            => $book->title,
                'author'           => $book->author,
                'publisher'        => $book->publisher,
                'isbn'             => $book->isbn,
                'publication_year' => $book->publication_year,
                'description'      => $book->description,
                'cover'            => $book->cover,
                'is_active'        => (bool) $book->is_active,
                'category_name'    => $book->category ? $book->category->name : 'Général',

                // Liste technique détaillée de tous les exemplaires physiques rattachés
                'copies' => $book->copies->map(function ($copy) {
                    return [
                        'id'               => $copy->id,
                        'inventory_number' => $copy->inventory_number, // Numéro d'étiquette unique
                        'status'           => $copy->status,           // available, borrowed, lost, damaged
                    ];
                }),

                // Métriques rapides d'analyse des stocks pour le bibliothécaire
                'stock_metrics' => [
                    'total_copies'     => $book->copies->count(),
                    'total_available'  => $book->copies->where('status', 'available')->count(),
                    'total_borrowed'   => $book->copies->where('status', 'borrowed')->count(),
                    'total_damaged'    => $book->copies->where('status', 'damaged')->count(),
                    'total_lost'       => $book->copies->where('status', 'lost')->count(),
                ]
            ];

            return response()->json($formatted, 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Cette œuvre littéraire n’existe pas ou a été retirée du catalogue.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors du chargement de la fiche de l’ouvrage.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Modifie les caractéristiques ou la photo de couverture d'un livre existant.
     * URL : POST /api/books/{id} (Sert pour l'émulation PUT avec FormData)
     */
    public function updateBook(Request $request, string $id)
    {
        try {
            $book = Book::findOrFail($id);

            // 1. Validation stricte des données modifiées
            $validated = $request->validate([
                'category_id'      => ['required', 'exists:book_categories,id'],
                'title'            => ['required', 'string', 'max:150'],
                'author'           => ['nullable', 'string', 'max:100'],
                'publisher'        => ['nullable', 'string', 'max:100'],
                'isbn'             => ['nullable', 'string', 'max:30'],
                'publication_year' => ['nullable', 'integer', 'min:1800', 'max:' . date('Y')],
                'description'      => ['nullable', 'string'],
                'cover'            => ['nullable', 'file', 'mimes:jpeg,png,jpg,webp', 'max:2048'], // Max 2 Mo
            ]);

            // 2. Traitement d'une nouvelle photo de couverture
            $coverPath = $book->cover; // Conserve l'ancien chemin par défaut
            
            if ($request->hasFile('cover')) {
                // Nettoyage : Supprimer l'ancienne image du serveur si elle existe
                if ($book->cover) {
                    $oldRelativePath = str_replace('/storage/', '', $book->cover);
                    if (Storage::disk('public')->exists($oldRelativePath)) {
                        Storage::disk('public')->delete($oldRelativePath);
                    }
                }
                
                // Stockage de la nouvelle image
                $file = $request->file('cover');
                $newPath = $file->store('library/covers', 'public');
                $coverPath = '/storage/' . $newPath;
            }

            // 3. Mise à jour de la fiche en base de données
            $book->update([
                'category_id'      => (int) $validated['category_id'],
                'title'            => trim($validated['title']),
                'author'           => $validated['author'] ? trim($validated['author']) : null,
                'publisher'        => $validated['publisher'] ? trim($validated['publisher']) : null,
                'isbn'             => $validated['isbn'] ? strtoupper(trim($validated['isbn'])) : null,
                'publication_year' => $validated['publication_year'] ? (int) $validated['publication_year'] : null,
                'description'      => $validated['description'] ?? null,
                'cover'            => $coverPath,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => "La fiche de l'ouvrage '{$book->title}' a été modifiée avec succès."
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Données de modification incorrectes.',
                'errors'  => $e->errors()
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Livre introuvable dans le catalogue.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la mise à jour de la fiche livre.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Retire un livre du catalogue et supprime sa couverture si aucun exemplaire n'est créé.
     * URL : DELETE /api/books/{id}
     */
    public function destroyBook(string $id)
    {
        try {
            // Récupère l'œuvre en comptant le nombre d'exemplaires physiques créés
            $book = Book::withCount('copies')->findOrFail($id);

            // 1. VERROU DE SÉCURITÉ : Bloquer s'il reste des exemplaires physiques en base de données
            if ($book->copies_count > 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Impossible de supprimer ce livre car " . $book->copies_count . " exemplaire(s) physique(s) porte(nt) encore cette référence dans l'inventaire. Veuillez d'abord supprimer les exemplaires rattachés."
                ], 422);
            }

            // 2. Nettoyage du fichier image de couverture sur le serveur de stockage
            if ($book->cover) {
                // Extraction du chemin relatif (retire le préfixe /storage/)
                $relativePath = str_replace('/storage/', '', $book->cover);
                if (Storage::disk('public')->exists($relativePath)) {
                    Storage::disk('public')->delete($relativePath);
                }
            }

            // 3. Suppression de la ligne de l'œuvre en base de données
            $book->delete();

            return response()->json([
                'status'  => 'success',
                'message' => "L'œuvre littéraire a été retirée définitivement du catalogue avec succès."
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Livre introuvable ou déjà supprimé.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur imprévue est survenue lors de la suppression de l’ouvrage.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }
    

    /**
 * Récupère le registre complet de tous les exemplaires physiques (Inventaire).
    * URL : GET /api/book-copies
    */
    public function indexCopies()
    {
        try {
            // Chargement de tous les exemplaires avec la relation de l'œuvre mère
            $copies = BookCopy::with(['book'])
                ->orderBy('inventory_number', 'asc')
                ->get();

            // Reformatage propre et plat pour un mapping ultra-simple sur React
            $formatted = $copies->map(function ($copy) {
                return [
                    'id'               => $copy->id,
                    'book_id'          => $copy->book_id,
                    'inventory_number' => $copy->inventory_number, // Numéro d'étiquette unique
                    'status'           => $copy->status,           // available, borrowed, lost, damaged
                    
                    // Extraction sécurisée des informations du livre
                    'book_title'       => $copy->book ? $copy->book->title : 'Ouvrage inconnu',
                    'book_author'      => $copy->book ? $copy->book->author : '—',
                ];
            });

            return response()->json($formatted, 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur technique est survenue lors du chargement de l’inventaire des livres.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Enregistre un nouvel exemplaire physique (étiquetage code-barres) en rayon.
     * URL : POST /api/book-copies
     */
    public function storeCopy(Request $request)
    {
        // 1. Validation stricte du livre parent et unicité du numéro d'inventaire
        $validated = $request->validate([
            'book_id'          => ['required', 'exists:books,id'],
            'inventory_number' => ['required', 'string', 'max:50', 'unique:book_copies,inventory_number'],
        ]);

        try {
            // 2. Nettoyage et forçage en majuscules (ex: dex-math-01 -> DEX-MATH-01)
            $validated['inventory_number'] = strtoupper(trim($validated['inventory_number']));
            $validated['status'] = 'available'; // Disponible en rayon d'office à la création

            // 3. Insertion propre dans la table book_copies
            $copy = BookCopy::create($validated);

            return response()->json([
                'status'  => 'success',
                'message' => "L'exemplaire N° [{$copy->inventory_number}] a été enregistré et ajouté au stock avec succès.",
                'copy'    => $copy
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => "Erreur de validation. Ce numéro d'inventaire est déjà attribué à un autre livre.",
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => "Une erreur imprévue est survenue lors de l'étiquetage de l'exemplaire.",
                'debug'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Modifie le statut physique d'un exemplaire (Disponible, Abîmé, Perdu).
     * URL : PUT /api/book-copies/{id}
     */
    public function updateCopyStatus(Request $request, string $id)
    {
        // 1. Validation de l'état demandé
        $validated = $request->validate([
            'status' => ['required', 'in:available,damaged,lost'],
        ]);

        try {
            $copy = BookCopy::findOrFail($id);

            // 2. SÉCURITÉ INVENTAIRE : Empêcher de modifier l'état d'un livre actuellement prêté
            if ($copy->status === 'borrowed') {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Opération refusée : Cet exemplaire est actuellement prêté. Veuillez d'abord enregistrer son retour au guichet avant de modifier son état physique."
                ], 422);
            }

            // 3. Application de la mise à jour
            $copy->update([
                'status' => $validated['status']
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => "L'état de l'exemplaire N° [{$copy->inventory_number}] a été actualisé avec succès."
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Exemplaire introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur technique est survenue lors du changement d’état.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supprime un exemplaire physique de l'inventaire (Mise au rebut).
     * URL : DELETE /api/book-copies/{id}
     */
    public function destroyCopy(string $id)
    {
        try {
            $copy = BookCopy::findOrFail($id);

            // 1. VERROU DE SÉCURITÉ : Interdire la suppression si le livre est actuellement chez un lecteur
            if ($copy->status === 'borrowed') {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Impossible de supprimer cet exemplaire car il est actuellement marqué comme 'Emprunté'. Veuillez d'abord enregistrer sa restitution."
                ], 422);
            }

            // 2. Suppression physique en base de données si l'exemplaire est disponible ou déclassé
            $copy->delete();

            return response()->json([
                'status'  => 'success',
                'message' => "L'exemplaire N° [{$copy->inventory_number}] a été définitivement retiré de l'inventaire de l'école."
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Exemplaire introuvable ou déjà supprimé.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur technique est survenue lors de la suppression de l’exemplaire.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupère le grand livre de l'historique des prêts et actualise les retards.
     * URL : GET /api/book-loans
     */
    public function indexLoans()
    {
        try {
            $today = Carbon::today()->format('Y-m-d');

            // 1. SÉCURITÉ ET AUTOMATISATION : Marquage automatique des livres en retard
            // Tout prêt toujours dehors ('borrowed') dont l'échéance est dépassée passe en 'late'
            BookLoan::where('status', 'borrowed')
                ->where('expected_return_date', '<', $today)
                ->update(['status' => 'late']);

            // 2. Extraction complète du registre avec ses liaisons en cascade
            $loans = BookLoan::with(['copy.book', 'student.classe', 'employee.position'])
                ->orderBy('id', 'desc')
                ->get();

            // 3. Reformatage adaptatif pour éliminer les structures complexes côté React
            $formatted = $loans->map(function ($loan) {
                $copy = $loan->copy;
                $book = $copy ? $copy->book : null;
                
                // Initialisation des variables d'identité de l'emprunteur
                $borrowerName    = '—';
                $borrowerSubtext = 'Inconnu';
                $borrowerType    = 'student';

                // Si c'est un élève
                if ($loan->student) {
                    $borrowerName    = $loan->student->first_name . ' ' . $loan->student->last_name;
                    $borrowerSubtext = 'Élève | Classe : ' . ($loan->student->classe ? $loan->student->classe->name : 'N/A');
                    $borrowerType    = 'student';
                } 
                // Si c'est un enseignant ou agent administratif (RH)
                elseif ($loan->employee) {
                    $borrowerName    = $loan->employee->first_name . ' ' . $loan->employee->last_name;
                    $borrowerSubtext = 'Personnel | Poste : ' . ($loan->employee->position ? $loan->employee->position->name : 'RH');
                    $borrowerType    = 'employee';
                }

                return [
                    'id'                   => $loan->id,
                    'loan_date'            => $loan->loan_date ? $loan->loan_date->format('Y-m-d') : null,
                    'expected_return_date' => $loan->expected_return_date ? $loan->expected_return_date->format('Y-m-d') : null,
                    'returned_at'          => $loan->returned_at ? $loan->returned_at->format('Y-m-d') : null,
                    'status'               => $loan->status, // borrowed, returned, late, lost
                    'notes'                => $loan->notes,

                    // Informations de l'étiquette d'inventaire
                    'inventory_number'     => $copy ? $copy->inventory_number : 'N/A',
                    'book_title'           => $book ? $book->title : 'Ouvrage supprimé',
                    'book_author'          => $book ? $book->author : '—',

                    // Structure unifiée de l'emprunteur lue par BookLoansPage.jsx
                    'borrower_name'        => $borrowerName,
                    'borrower_subtext'     => $borrowerSubtext,
                    'borrower_type'        => $borrowerType,
                ];
            });

            return response()->json($formatted, 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur imprévue est survenue lors de l’analyse du registre des prêts.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Enregistre la sortie d'un livre (Émission d'un prêt).
     * URL : POST /api/book-loans
     */
    public function storeLoan(Request $request)
    {
        assert_writable_year();
        // 1. Validation rigoureuse de la fiche de sortie
        $validated = $request->validate([
            'book_copy_id'         => ['required', 'exists:book_copies,id'],
            'student_id'           => ['nullable', 'exists:students,id', 'required_without:employee_id'],
            'employee_id'          => ['nullable', 'exists:employees,id', 'required_without:student_id'],
            'loan_date'            => ['required', 'date'],
            'expected_return_date' => ['required', 'date', 'after_or_equal:loan_date'],
            'notes'                => ['nullable', 'string'],
        ]);

        try {

            // 2. Extraction de l'année académique active
            $activeYearId = current_active_year_id();

            if (!$activeYearId) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Opération annulée : aucune année académique n'est marquée comme active."
                ], 422);
            }

            // 3. Recherche et contrôle de l'exemplaire physique
            $copy = BookCopy::findOrFail($validated['book_copy_id']);

            if ($copy->status !== 'available') {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Opération refusée : cet exemplaire n'est pas disponible en rayon actuellement (Statut actuel : {$copy->status})."
                ], 422);
            }

            // 4. Exécution unifiée de l'emprunt (Double écriture SQL)
            return DB::transaction(function () use ($validated, $copy, $activeYearId) {
                
                // Création de l'enregistrement de prêt
                $loan = BookLoan::create([
                    'book_copy_id'         => $copy->id,
                    'academic_year_id'     => $activeYearId,
                    'student_id'           => $validated['student_id'] ?? null,
                    'employee_id'          => $validated['employee_id'] ?? null,
                    'loan_date'            => $validated['loan_date'],
                    'expected_return_date' => $validated['expected_return_date'],
                    'status'               => 'borrowed',
                    'notes'                => $validated['notes'] ?? null,
                    'created_by'           => Auth::id() ?? null,
                ]);

                // Verrouillage de l'exemplaire physique
                $copy->update(['status' => 'borrowed']);

                return response()->json([
                    'status'  => 'success',
                    'message' => "Le prêt de l'exemplaire N° [{$copy->inventory_number}] a été validé et enregistré au guichet.",
                    'loan'    => $loan
                ], 201);
            });

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => "Une erreur imprévue est survenue lors de l'émission du prêt.",
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Enregistre le retour physique d'un livre et le remet en rayon.
     * URL : POST /api/book-loans/{id}/return
     */
    public function returnBook(string $id)
    {
        assert_writable_year();
        try {
            // 1. Recherche de la fiche de prêt en cours (Emprunté ou En retard)
            $loan = BookLoan::whereIn('status', ['borrowed', 'late'])->findOrFail($id);
            
            // 2. Récupération de l'exemplaire physique associé
            $copy = BookCopy::findOrFail($loan->book_copy_id);

            // 3. Exécution de la restitution au sein d'une transaction SQL sécurisée
            return DB::transaction(function () use ($loan, $copy) {
                
                // Clôture de la fiche de prêt
                $loan->update([
                    'returned_at' => Carbon::now()->format('Y-m-d'),
                    'status'      => 'returned',
                ]);

                // Libération et remise en rayon immédiate de l'exemplaire physique
                $copy->update([
                    'status' => 'available'
                ]);

                return response()->json([
                    'status'  => 'success',
                    'message' => "La restitution de l'exemplaire N° [{$copy->inventory_number}] a été validée. Le livre est à nouveau disponible en rayon."
                ], 200);
            });

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => "Cette fiche de prêt n'existe pas, a déjà été clôturée ou le livre est marqué comme perdu."
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => "Une erreur technique est survenue lors de l'enregistrement du retour de l'ouvrage.",
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Moteur de recherche unifié pour trouver un emprunteur (Élève ou Personnel RH).
     * URL : GET /api/library/search-borrowers?q=...
     */
    public function searchBorrowers(Request $request)
    {
        $query = $request->query('q');

        if (strlen(trim($query)) < 2) {
            return response()->json([], 200);
        }

        try {
            // 1. RECHERCHE DANS LES ÉLÈVES
            $students = Student::with('classe')
                ->where('first_name', 'LIKE', "%{$query}%")
                ->orWhere('last_name', 'LIKE', "%{$query}%")
                ->orWhere('matricule', 'LIKE', "%{$query}%")
                ->take(5)
                ->get();

            // 2. RECHERCHE DANS LE PERSONNEL (RH / Enseignants)
            $employees = Employee::with('position')
                ->where('first_name', 'LIKE', "%{$query}%")
                ->orWhere('last_name', 'LIKE', "%{$query}%")
                ->where('status', 'active')
                ->take(5)
                ->get();

            // 3. FUSION ET HARMONISATION UNIFIÉE POUR LE COMPOSANT REACT
            $results = collect();

            foreach ($students as $s) {
                $results->push([
                    'id'       => $s->id,
                    'type'     => 'student', // Clé lue par le payload React
                    'name'     => $s->last_name . ' ' . $s->first_name,
                    'subtext'  => 'Matricule : ' . $s->matricule . ' | Classe : ' . ($s->classe ? $s->classe->name : 'N/A')
                ]);
            }

            foreach ($employees as $e) {
                $results->push([
                    'id'       => $e->id,
                    'type'     => 'employee', // Clé lue par le payload React
                    'name'     => $e->last_name . ' ' . $e->first_name,
                    'subtext'  => 'Personnel RH | Poste : ' . ($e->position ? $e->position->name : 'Non défini')
                ]);
            }

            return response()->json($results, 200);

        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
 * Moteur de recherche prédictif pour les exemplaires disponibles en rayon (Saisie/Scan).
 * URL : GET /api/library/search-available-copies?q=...
 */
    public function searchAvailableCopies(Request $request)
    {
        $query = $request->query('q');

        // Sécurité : Éviter de surcharger MySQL si la saisie est trop courte
        if (strlen(trim($query)) < 2) {
            return response()->json([], 200);
        }

        try {
            // Recherche des exemplaires uniquement disponibles en rayon
            $copies = BookCopy::with(['book.category'])
                ->where('status', 'available')
                ->where(function($q) use ($query) {
                    $q->where('inventory_number', 'LIKE', "%{$query}%")
                    ->orWhereHas('book', function($subQ) use ($query) {
                        $subQ->where('title', 'LIKE', "%{$query}%");
                    });
                })
                ->take(5) // Limite aux 5 meilleurs résultats pour la fluidité de l'interface
                ->get();

            // Harmonisation des données lues par le formulaire React
            $formatted = $copies->map(function ($copy) {
                return [
                    'id'               => $copy->id,
                    'inventory_number' => $copy->inventory_number,
                    'book_title'       => $copy->book ? $copy->book->title : 'Ouvrage inconnu',
                    'book_author'      => $copy->book ? $copy->book->author : '—',
                    'category_name'    => ($copy->book && $copy->book->category) ? $copy->book->category->name : 'Général',
                ];
            });

            return response()->json($formatted, 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors de la recherche de l’exemplaire physique.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }


}
