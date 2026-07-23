<?php

namespace App\Http\Controllers;

use App\Models\Academic\AcademicYears;
use App\Models\Personel\Employee;
use App\Models\Transport\TransportRoute;
use App\Models\Transport\TransportSubscription;
use App\Models\Transport\Vehicle;
use App\Models\Transport\VehicleExpense;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TransportController extends Controller
{
    //

    /**
     * Récupère les indicateurs analytiques et comptables du tableau de bord de transport.
     * URL: GET /api/transport/dashboard
     */
    public function dashboardMetrics()
    {
        try {
            $today = Carbon::today()->format('Y-m-d');
            $startOfMonth = Carbon::now()->startOfMonth()->format('Y-m-d');
            $endOfMonth = Carbon::now()->endOfMonth()->format('Y-m-d');

            // 1. Nombre d'élèves transportés (Abonnements actifs à la date du jour)
            $totalStudents = TransportSubscription::where('status', 'active')
                ->where('start_date', '<=', $today)
                ->where('end_date', '>=', $today)
                ->count();

            // 2. Recettes de transport encaissées pour le mois en cours (Cumul des acomptes et versements transport)
            $totalReceipts = TransportSubscription::whereBetween('created_at', [$startOfMonth . ' 00:00:00', $endOfMonth . ' 23:59:59'])
                ->sum('amount_paid');

            // 3. Ventilation analytique détaillée des dépenses exigées par la direction (FCFA)
            $fuelExpenses = VehicleExpense::where('category', 'fuel')
                ->whereBetween('expense_date', [$startOfMonth, $endOfMonth])
                ->sum('amount');

            $washingExpenses = VehicleExpense::where('category', 'washing')
                ->whereBetween('expense_date', [$startOfMonth, $endOfMonth])
                ->sum('amount');

            $maintenanceExpenses = VehicleExpense::where('category', 'repair')
                ->whereBetween('expense_date', [$startOfMonth, $endOfMonth])
                ->sum('amount');

            $salaryExpenses = VehicleExpense::where('category', 'salary')
                ->whereBetween('expense_date', [$startOfMonth, $endOfMonth])
                ->sum('amount');

            // 4. Calcul des totaux et du solde net de rentabilité (Recettes - Dépenses)
            $totalReceiptsInt = (int) $totalReceipts;
            
            $totalExpenses = (int) ($fuelExpenses + $washingExpenses + $maintenanceExpenses + $salaryExpenses);
            $netBalance = $totalReceiptsInt - $totalExpenses;
            $isProfit = $netBalance >= 0;

            // 5. PILOTAGE ANALYTIQUE : Coût réel du transport par élève (Total des charges / effectif transporté)
            // Sécurité contre la division par zéro si aucun élève n'est encore inscrit
            $realCostPerStudent = $totalStudents > 0 ? round($totalExpenses / $totalStudents) : 0;

            // 6. Envoi de la réponse structurée lue par TransportDashboardPage.jsx
            return response()->json([
                'total_students_transported' => (int) $totalStudents,
                'total_receipts'             => $totalReceiptsInt,
                'fuel_expenses'              => (int) $fuelExpenses,
                'washing_expenses'           => (int) $washingExpenses,
                'maintenance_expenses'       => (int) $maintenanceExpenses,
                'salary_expenses'            => (int) $salaryExpenses,
                'total_expenses'             => $totalExpenses,
                'net_balance'                => (int) $netBalance,
                'is_profit'                  => (bool) $isProfit,
                'real_cost_per_student'      => (int) $realCostPerStudent
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur technique lors de la génération du bilan analytique du transport.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    
    /**
     * Récupère la liste complète de la flotte automobile avec les chauffeurs RH.
     * URL: GET /api/vehicles
     */
    public function indexVehicles()
    {
        try {
            // Chargement optimisé avec la relation chauffeur (issue de votre module Personnel RH)
            $vehicles = Vehicle::with(['driver'])
                ->orderBy('id', 'desc')
                ->get();

            // Reformatage propre pour simplifier la lecture des objets par l'interface React
            $formatted = $vehicles->map(function ($veh) {
                $driver = $veh->driver;

                return [
                    'id'                  => $veh->id,
                    'name'                => $veh->name, // ex: Car de Ramassage N°1
                    'registration_number' => $veh->registration_number, // Plaque d'immatriculation
                    'brand'               => $veh->brand,
                    'model'               => $veh->model,
                    'capacity'            => (int) $veh->capacity, // Volume de places assises
                    'purchase_date'       => $veh->purchase_date ? $veh->purchase_date->format('Y-m-d') : null,
                    'is_active'           => (bool) $veh->is_active, // true = En ligne, false = Garage/En panne
                    'driver_id'           => $veh->driver_id,

                    // Informations simplifiées du chauffeur pour le tableau React
                    'driver' => $driver ? [
                        'id'         => $driver->id,
                        'first_name' => $driver->first_name,
                        'last_name'  => $driver->last_name,
                        'phone'      => $driver->phone, // Téléphone pour joindre le chauffeur en car
                    ] : null,
                ];
            });

            return response()->json($formatted, 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la récupération du parc automobile.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Enregistre un nouveau bus scolaire dans le parc automobile.
     * URL: POST /api/vehicles
     */
    public function storeVehicle(Request $request)
    {
        // 1. Validation stricte des données techniques du bus
        $validated = $request->validate([
            'name'                => ['required', 'string', 'max:100'],
            'registration_number' => ['required', 'string', 'max:50', 'unique:vehicles,registration_number'], // Plaque d'immatriculation unique
            'brand'               => ['nullable', 'string', 'max:100'],
            'model'               => ['nullable', 'string', 'max:100'],
            'capacity'            => ['required', 'integer', 'min:1'], // Nombre de places assises
            'purchase_date'       => ['nullable', 'date'],
            'driver_id'           => ['nullable', 'exists:employees,id'], // ID du chauffeur issu du Personnel RH
        ]);

        try {
            // 2. Standardisation de l'immatriculation en lettres majuscules (ex: 2450gz01 -> 2450GZ01)
            $validated['registration_number'] = strtoupper(trim($validated['registration_number']));
            $validated['is_active'] = true; // Disponible en ligne d'office à l'enregistrement

            // 3. Insertion propre dans la table vehicles
            $vehicle = Vehicle::create($validated);

            return response()->json([
                'status'  => 'success',
                'message' => "Le véhicule '{$vehicle->name}' [{$vehicle->registration_number}] a été intégré au parc automobile avec succès.",
                'vehicle' => $vehicle
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => "Erreur de validation. Vérifiez que cette plaque d'immatriculation n'est pas déjà enregistrée.",
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur imprévue est survenue lors de l’ajout du véhicule.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Affiche la fiche technique détaillée d'un bus avec ses lignes et son historique de charges.
     * URL: GET /api/vehicles/{id}
     */
    public function showVehicle(string $id)
    {
        try {
            // Chargement du véhicule avec toutes ses liaisons comptables et opérationnelles
            $vehicle = Vehicle::with([
                'driver',
                'routes',
                'expenses' => function ($query) {
                    $query->orderBy('expense_date', 'desc')->take(20); // Les 20 derniers frais du véhicule
                }
            ])->findOrFail($id);

            // Reformatage propre pour un affichage fluide dans les onglets React
            $formatted = [
                'id'                  => $vehicle->id,
                'name'                => $vehicle->name,
                'registration_number' => $vehicle->registration_number,
                'brand'               => $vehicle->brand,
                'model'               => $vehicle->model,
                'capacity'            => (int) $vehicle->capacity,
                'purchase_date'       => $vehicle->purchase_date ? $vehicle->purchase_date->format('Y-m-d') : null,
                'is_active'           => (bool) $vehicle->is_active,

                // Chauffeur attitré (Module Personnel)
                'driver' => $vehicle->driver ? [
                    'id'         => $vehicle->driver->id,
                    'full_name'  => $vehicle->driver->first_name . ' ' . $vehicle->driver->last_name,
                    'phone'      => $vehicle->driver->phone,
                ] : null,

                // Liste de toutes les lignes de bus desservies par ce véhicule
                'routes' => $vehicle->routes->map(function ($route) {
                    return [
                        'id'              => $route->id,
                        'name'            => $route->name,
                        'departure_point' => $route->departure_point,
                        'arrival_point'   => $route->arrival_point,
                        'monthly_fee'     => (int) $route->monthly_fee,
                        'is_active'       => (bool) $route->is_active,
                    ];
                }),

                // Historique financier propre à ce car de ramassage (FCFA)
                'expenses' => $vehicle->expenses->map(function ($exp) {
                    return [
                        'id'           => $exp->id,
                        'title'        => $exp->title, // ex: Achat batterie 12V
                        'amount'       => (int) $exp->amount,
                        'category'     => $exp->category, // fuel, washing, repair...
                        'expense_date' => $exp->expense_date ? $exp->expense_date->format('Y-m-d') : null,
                    ];
                }),
                
                // Total des dépenses enregistrées sur la vie de ce véhicule
                'total_expenses_lifetime' => (int) $vehicle->expenses->sum('amount')
            ];

            return response()->json($formatted, 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Ce véhicule n’existe pas ou a été retiré du parc.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de l’analyse du dossier du véhicule.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Modifie les caractéristiques techniques ou le chauffeur d'un bus scolaire.
     * URL: PUT /api/vehicles/{id}
     */
    public function updateVehicle(Request $request, string $id)
    {
        try {
            $vehicle = Vehicle::findOrFail($id);

            // 1. Validation stricte des informations modifiées
            $validated = $request->validate([
                'name'                => ['required', 'string', 'max:100'],
                // L'immatriculation doit rester unique, mais on ignore l'ID du bus actuel
                'registration_number' => ['required', 'string', 'max:50', Rule::unique('vehicles', 'registration_number')->ignore($id)],
                'brand'               => ['nullable', 'string', 'max:100'],
                'model'               => ['nullable', 'string', 'max:100'],
                'capacity'            => ['required', 'integer', 'min:1'],
                'purchase_date'       => ['nullable', 'date'],
                'driver_id'           => ['nullable', 'exists:employees,id'], // ID du nouveau chauffeur
                'is_active'           => ['required', 'boolean'],
            ]);

            // 2. Standardisation mécanique de l'immatriculation
            $validated['registration_number'] = strtoupper(trim($validated['registration_number']));

            // 3. Application de la mise à jour en base de données
            $vehicle->update($validated);

            return response()->json([
                'status'  => 'success',
                'message' => "La fiche technique du véhicule '{$vehicle->name}' a été mise à jour avec succès."
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur de validation. Vérifiez que la plaque saisie n’est pas affectée à un autre bus.',
                'errors'  => $e->errors()
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Véhicule introuvable.'
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
     * Retire définitivement un véhicule du parc automobile s'il n'est rattaché à aucune ligne.
     * URL: DELETE /api/vehicles/{id}
     */
    public function destroyVehicle(string $id)
    {
        try {
            // Compte le nombre de circuits affectés à ce bus scolaire
            $vehicle = Vehicle::withCount('routes')->findOrFail($id);

            // 1. VERROU DE SÉCURITÉ OPÉRATIONNEL : Bloquer si le bus est affecté à des lignes de transport
            if ($vehicle->routes_count > 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Impossible de supprimer ce véhicule car " . $vehicle->routes_count . " ligne(s) de bus active(s) l'utilisent actuellement pour le ramassage. Veuillez plutôt passer son état à 'Garage' en décochant la case 'Disponible'."
                ], 422);
            }

            // 2. Suppression physique en base de données si le car est totalement libéré
            $vehicle->delete();

            return response()->json([
                'status'  => 'success',
                'message' => "Le véhicule a été retiré du parc automobile de l'école avec succès."
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Véhicule introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors du retrait du véhicule.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Récupère la liste des employés au poste de Chauffeur pour alimenter les sélecteurs React.
     * URL: GET /api/transport/available-drivers
     */
    public function getAvailableDrivers()
    {
        try {
            // Extraction des employés dont le poste est lié au métier de Chauffeur
            // On vérifie le nom ou le slug de la relation 'position' (Poste métier)
            $drivers = Employee::with('position')
                ->whereHas('position', function($query) {
                    $query->where('slug', 'chauffeur')
                        ->orWhere('name', 'LIKE', '%chauffeur%')
                        ->orWhere('name', 'LIKE', '%conducteur%');
                })
                ->where('status', 'active') // Uniquement les chauffeurs en poste actuellement
                ->orderBy('last_name', 'asc')
                ->get();

            // Reformatage épuré pour le composant select de React VehiclesListPage.jsx
            $formatted = $drivers->map(function ($drv) {
                return [
                    'id'         => $drv->id,
                    'first_name' => $drv->first_name,
                    'last_name'  => $drv->last_name,
                    'phone'      => $drv->phone,
                ];
            });

            return response()->json($formatted, 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Impossible de charger le registre des chauffeurs de l’établissement.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupère le registre complet des circuits et lignes de ramassage configurés.
     * URL: GET /api/transport-routes
     */
    public function indexRoutes()
    {
        try {
            // Chargement optimisé avec les données du véhicule rattaché
            $routes = TransportRoute::with(['vehicle'])
                ->orderBy('name', 'asc')
                ->get();

            // Reformatage propre des structures pour sécuriser les types monétaires en Franc CFA
            $formatted = $routes->map(function ($route) {
                return [
                    'id'              => $route->id,
                    'vehicle_id'      => $route->vehicle_id,
                    'name'            => $route->name,            // ex: Ligne 1 - Yamoussoukro Ouest
                    'departure_point' => $route->departure_point, // ex: Morofé
                    'arrival_point'   => $route->arrival_point,   // ex: 220 Logements
                    'stops_circuit'   => $route->stops_circuit,   // Itinéraire textuel libre desservi
                    'monthly_fee'     => (int) $route->monthly_fee, // Forçage en entier pour le Franc CFA
                    'is_active'       => (bool) $route->is_active,  // true = Ouverte, false = Fermée

                    // Véhicule lié
                    'vehicle' => $route->vehicle ? [
                        'id'                  => $route->vehicle->id,
                        'name'                => $route->vehicle->name,
                        'registration_number' => $route->vehicle->registration_number, // Plaque d'immatriculation
                    ] : null,
                ];
            });

            return response()->json($formatted, 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la récupération des lignes de transport.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Enregistre un nouvel itinéraire / circuit de transport scolaire.
     * URL: POST /api/transport-routes
     */
    public function storeRoute(Request $request)
    {
        // 1. Validation stricte du circuit (Tarif obligatoirement entier positif pour le FCFA)
        $validated = $request->validate([
            'vehicle_id'      => ['required', 'exists:vehicles,id'],
            'name'            => ['required', 'string', 'max:150'],
            'departure_point' => ['required', 'string', 'max:150'],
            'arrival_point'   => ['required', 'string', 'max:150'],
            'stops_circuit'   => ['nullable', 'string'], // Arrêts textuels libres (Morofé, Dioulakro...)
            'monthly_fee'     => ['required', 'integer', 'min:0'], // Forfait mensuel en FCFA
        ]);

        try {
            $validated['is_active'] = true; // Ouverte aux inscriptions par défaut

            // 2. Insertion propre en base de données
            $route = TransportRoute::create($validated);

            return response()->json([
                'status'  => 'success',
                'message' => "La ligne de ramassage '{$route->name}' a été ouverte avec succès au forfait mensuel de " . number_format($route->monthly_fee, 0, '', ' ') . " FCFA.",
                'route'   => $route
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur de saisie dans les champs de l’itinéraire.',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur imprévue est survenue lors de la création du circuit de transport.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Affiche la fiche détaillée d'un circuit avec le bus et la liste des élèves abonnés.
     * URL: GET /api/transport-routes/{id}
     */
    public function showRoute(string $id)
    {
        try {
            // Chargement de la ligne avec le véhicule, son chauffeur et les élèves inscrits
            $route = TransportRoute::with([
                'vehicle.driver',
                'subscriptions.student.classe'
            ])->findOrFail($id);

            // Reformatage propre des structures pour l'interface React
            $formatted = [
                'id'              => $route->id,
                'name'            => $route->name,
                'departure_point' => $route->departure_point,
                'arrival_point'   => $route->arrival_point,
                'stops_circuit'   => $route->stops_circuit,
                'monthly_fee'     => (int) $route->monthly_fee, // Forçage en Franc CFA
                'is_active'       => (bool) $route->is_active,

                // Informations du matériel de transport assigné
                'vehicle' => $route->vehicle ? [
                    'id'                  => $route->vehicle->id,
                    'name'                => $route->vehicle->name,
                    'registration_number' => $route->vehicle->registration_number,
                    'driver_name'         => $route->vehicle->driver 
                        ? $route->vehicle->driver->first_name . ' ' . $route->vehicle->driver->last_name 
                        : 'Aucun chauffeur assigné',
                ] : null,

                // Fichier complet des élèves qui utilisent cette ligne
                'subscribers' => $route->subscriptions->map(function ($sub) {
                    $student = $sub->student;
                    return [
                        'subscription_id' => $sub->id,
                        'student_id'      => $student ? $student->id : null,
                        'matricule'       => $student ? $student->matricule : 'N/A',
                        'full_name'       => $student ? $student->first_name . ' ' . $student->last_name : 'Élève inconnu',
                        'class_name'      => ($student && $student->classe) ? $student->classe->name : 'N/A',
                        'status'          => $sub->status, // active, suspended, inactive
                        'payment_status'  => $sub->payment_status // paid, partial, unpaid
                    ];
                }),
                
                // Statistique d'occupation pour le gestionnaire
                'total_subscribers_count' => $route->subscriptions->where('status', 'active')->count()
            ];

            return response()->json($formatted, 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Ligne de transport introuvable ou fermée.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de l’analyse de la ligne.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Modifie les caractéristiques ou la tarification d'un circuit de transport existant.
     * URL: PUT /api/transport-routes/{id}
     */
    public function updateRoute(Request $request, string $id)
    {
        try {
            $route = TransportRoute::findOrFail($id);

            // 1. Validation stricte des modifications (FCFA strict entier positif)
            $validated = $request->validate([
                'vehicle_id'      => ['required', 'exists:vehicles,id'],
                'name'            => ['required', 'string', 'max:150'],
                'departure_point' => ['required', 'string', 'max:150'],
                'arrival_point'   => ['required', 'string', 'max:150'],
                'stops_circuit'   => ['nullable', 'string'],
                'monthly_fee'     => ['required', 'integer', 'min:0'], // Nouveau forfait mensuel
                'is_active'       => ['required', 'boolean'],
            ]);

            // 2. Application de la mise à jour sécurisée avec la syntaxe native PHP
            $route->update([
                'vehicle_id'      => (int) $validated['vehicle_id'],
                'name'            => trim($validated['name']),
                'departure_point' => trim($validated['departure_point']),
                'arrival_point'   => trim($validated['arrival_point']),
                'stops_circuit'   => $validated['stops_circuit'] ? trim($validated['stops_circuit']) : null,
                'monthly_fee'     => (int) $validated['monthly_fee'], // Remplacement du Math.round par un cast PHP strict
                'is_active'       => (bool) $validated['is_active'],
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => "L'itinéraire de la ligne '{$route->name}' a été révisé et mis à jour avec succès."
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur de validation. Veuillez vérifier les informations saisies.',
                'errors'  => $e->errors()
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Ligne de transport introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur imprévue est survenue lors de la mise à jour du circuit.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supprime un itinéraire de transport s'il n'est lié à aucun historique d'élèves.
     * URL: DELETE /api/transport-routes/{id}
     */
    public function destroyRoute(string $id)
    {
        try {
            // Récupère la ligne en comptant le nombre d'élèves abonnés
            $route = TransportRoute::withCount('subscriptions')->findOrFail($id);

            // 1. VERROU DE SÉCURITÉ COMPTABLE : Empêcher la suppression si des élèves y sont inscrits
            if ($route->subscriptions_count > 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Impossible de supprimer cette ligne car " . $route->subscriptions_count . " élève(s) y sont actuellement inscrit(s). Veuillez plutôt la fermer en décochant la case 'Ligne ouverte aux inscriptions' depuis le configurateur."
                ], 422);
            }

            // 2. Suppression de la ligne si la table est libre de toute attache
            $route->delete();

            return response()->json([
                'status'  => 'success',
                'message' => 'La ligne de transport a été retirée du catalogue de l’école avec succès.'
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Ligne de transport introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la suppression de l’itinéraire.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Liste globale de tous les abonnements de transport avec profils élèves et classes.
     * URL: GET /api/transport-subscriptions
     */
   public function indexSubscriptions()
{
    try {
        // ── LE CORRECTIF AUTOMATIQUE : Désactive les cartes périmées à la seconde où on charge la page
        $todayStr = \Illuminate\Support\Carbon::today()->format('Y-m-d');
        
        TransportSubscription::where('status', 'active')
            ->where('end_date', '<', $todayStr)
            ->update(['status' => 'inactive']); // Modifie directement la base de données pour les retardataires

        // 1. Suite du code : Chargement des abonnements avec les relations
        $subscriptions = TransportSubscription::with(['student.classe', 'route'])
            ->latest()
            ->get();

        // 2. Reformatage adaptatif pour React (Le reste de votre fonction indexSubscriptions...)
        $formatted = $subscriptions->map(function ($sub) {
            $student = $sub->student;
            return [
                'id'             => $sub->id,
                'start_date'     => $sub->start_date ? $sub->start_date->format('Y-m-d') : null,
                'end_date'       => $sub->end_date ? $sub->end_date->format('Y-m-d') : null,
                'status'         => $sub->status,          
                'payment_status' => $sub->payment_status,   
                'notes'          => $sub->notes,
                'total_amount'   => (int) $sub->total_amount,
                'amount_paid'    => (int) $sub->amount_paid,
                'remaining'      => (int) max(0, $sub->total_amount - $sub->amount_paid),
                'student' => $student ? [
                    'id'         => $student->id,
                    'matricule'  => $student->matricule,
                    'first_name' => $student->first_name,
                    'last_name'  => $student->last_name,
                    'gender'     => $student->gender,
                    'photo'      => $student->photo, 
                ] : null,
                'class_name' => ($student && $student->classe) ? $student->classe->name : 'Non affecté',
                'route' => $sub->route ? [
                    'id'   => $sub->route->id,
                    'name' => $sub->route->name,
                ] : null,
            ];
        });

        return response()->json($formatted, 200);

    } catch (\Exception $e) {
        return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}


    /**
     * Enregistre un nouvel abonnement de transport pour un élève (Carte de car).
     * URL: POST /api/transport-subscriptions
     */
    public function storeSubscription(Request $request)
    {
         assert_writable_year();
        $validated = $request->validate([
            'student_id'   => ['required', 'exists:students,id'],
            'route_id'     => ['required', 'exists:transport_routes,id'],
            'start_date'   => ['required', 'date'],
            'end_date'     => ['required', 'date', 'after_or_equal:start_date'],
            'total_amount' => ['required', 'integer', 'min:0'],
            'amount_paid'  => ['required', 'integer', 'min:0'],
            'notes'        => ['nullable', 'string'],
        ]);

        try {
            // 2. Récupérer l'ID de l'année académique active automatiquement
            // Ajustez le chemin vers votre modèle exact d'année scolaire si nécessaire
            $activeYearId = AcademicYears::where('is_active', 1)->value('id');

            if (!$activeYearId) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Opération impossible : Aucune année académique n'est active actuellement sur le système."
                ], 422);
            }

            // 3. SÉCURITÉ ANTI-DOUBLON : Un élève ne peut pas avoir deux cartes de bus pour la même année
            $exists = TransportSubscription::where('student_id', $validated['student_id'])
                ->where('academic_year_id', $activeYearId)
                ->exists();

            if ($exists) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Cet élève dispose déjà d'un dossier d'abonnement actif pour le transport sur cette année scolaire."
                ], 422);
            }

            // 4. Ventilation automatique des indicateurs de caisse transport (FCFA)
            $total = (int) $validated['total_amount'];
            $paid  = (int) $validated['amount_paid'];

            if ($paid >= $total) {
                $paymentStatus = 'paid';    // Intégralement soldé
            } elseif ($paid > 0 && $paid < $total) {
                $paymentStatus = 'partial'; // Acompte / Avance enregistrée
            } else {
                $paymentStatus = 'unpaid';  // Aucun versement de départ effectué
            }

            // 5. Enregistrement propre dans la table transport_subscriptions
            $subscription = TransportSubscription::create([
                'student_id'       => $validated['student_id'],
                'academic_year_id' => $activeYearId,
                'route_id'         => (int) $validated['route_id'],
                'start_date'       => $validated['start_date'],
                'end_date'         => $validated['end_date'],
                'total_amount'     => $total,
                'amount_paid'      => $paid,
                'status'           => 'active', // Actif d'office à la création
                'payment_status'   => $paymentStatus,
                'notes'            => $validated['notes'] ?? null,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => "L'élève a été affecté avec succès à sa ligne de ramassage. Carte de bus générée.",
                'subscription' => $subscription
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => "Une erreur imprévue est survenue lors de l'établissement de la carte de transport.",
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Affiche les détails complets de la carte de bus d'un élève.
     * URL: GET /api/transport-subscriptions/{id}
     */
    public function showSubscription(string $id)
    {
        try {
            // Chargement en cascade des relations indispensables
            $subscription = TransportSubscription::with([
                'student.classe', 
                'route.vehicle.driver'
            ])->findOrFail($id);

            $student = $subscription->student;
            $route = $subscription->route;

            // Reformatage propre des structures pour l'arborescence React
            $formatted = [
                'id'             => $subscription->id,
                'start_date'     => $subscription->start_date ? $subscription->start_date->format('Y-m-d') : null,
                'end_date'       => $subscription->end_date ? $subscription->end_date->format('Y-m-d') : null,
                'status'         => $subscription->status,          // active, inactive, suspended
                'payment_status' => $subscription->payment_status,   // unpaid, partial, paid
                'notes'          => $subscription->notes,
                
                // Comptabilité analytique en Franc CFA (Entiers)
                'total_amount'   => (int) $subscription->total_amount,
                'amount_paid'    => (int) $subscription->amount_paid,
                'remaining'      => (int) max(0, $subscription->total_amount - $subscription->amount_paid),

                // Profil permanent de l'élève
                'student' => $student ? [
                    'id'          => $student->id,
                    'matricule'   => $student->matricule,
                    'first_name'  => $student->first_name,
                    'last_name'   => $student->last_name,
                    'gender'      => $student->gender,
                    'photo'       => $student->photo,
                    'class_name'  => $student->classe ? $student->classe->name : 'N/A',
                ] : null,

                // Détails de l'itinéraire et du véhicule rattaché
                'route' => $route ? [
                    'id'              => $route->id,
                    'name'            => $route->name,
                    'departure_point' => $route->departure_point,
                    'arrival_point'   => $route->arrival_point,
                    'stops_circuit'   => $route->stops_circuit,
                    
                    // Véhicule et Chauffeur assignés au circuit
                    'vehicle' => $route->vehicle ? [
                        'id'                  => $route->vehicle->id,
                        'name'                => $route->vehicle->name,
                        'registration_number' => $route->vehicle->registration_number,
                        'driver_name'         => $route->vehicle->driver 
                            ? $route->vehicle->driver->first_name . ' ' . $route->vehicle->driver->last_name 
                            : 'Aucun chauffeur affecté',
                        'driver_phone'        => $route->vehicle->driver ? $route->vehicle->driver->phone : null,
                    ] : null,
                ] : null,
            ];

            return response()->json($formatted, 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Fiche d’abonnement de transport introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la récupération du dossier de transport.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Modifie les dates, le trajet ou le statut de l'abonnement de transport d'un élève.
     * URL: PUT /api/transport-subscriptions/{id}
     */
    public function updateSubscription(Request $request, string $id)
    {   
         assert_writable_year();
        try {
            $subscription = TransportSubscription::findOrFail($id);

            // 1. Validation stricte des données modifiées (FCFA strict entier positif)
            $validated = $request->validate([
                'route_id'     => ['required', 'exists:transport_routes,id'],
                'start_date'   => ['required', 'date'],
                'end_date'     => ['required', 'date', 'after_or_equal:start_date'],
                'total_amount' => ['required', 'integer', 'min:0'],
                'status'       => ['required', 'in:active,inactive,suspended'], // active = En ligne, suspended = Carte bloquée
                'notes'        => ['nullable', 'string'],
            ]);

            // 2. Recalcul dynamique du statut de recouvrement en caisse transport (FCFA)
            $total = (int) $validated['total_amount'];
            $paid  = (int) $subscription->amount_paid; // On fige l'argent déjà encaissé par le passé

            if ($paid >= $total) {
                $paymentStatus = 'paid';
            } elseif ($paid > 0 && $paid < $total) {
                $paymentStatus = 'partial';
            } else {
                $paymentStatus = 'unpaid';
            }

            // 3. Application de la mise à jour en base de données
            $subscription->update([
                'route_id'       => (int) $validated['route_id'],
                'start_date'     => $validated['start_date'],
                'end_date'       => $validated['end_date'],
                'total_amount'   => $total,
                'status'         => $validated['status'],
                'payment_status' => $paymentStatus,
                'notes'          => $validated['notes'] ?? null,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => "La carte de transport de l'élève a été mise à jour et recalculée avec succès."
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Données de mise à jour de carte de bus incorrectes.',
                'errors'  => $e->errors()
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Dossier d’abonnement de transport introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur imprévue est survenue lors de la mise à jour du dossier de transport.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Supprime la fiche d'abonnement de transport d'un élève (Sécurisé).
     * URL: DELETE /api/transport-subscriptions/{id}
     */
    public function destroySubscription(string $id)
    {
        try {
            $subscription = TransportSubscription::findOrFail($id);

            // 1. VERROU DE SÉCURITÉ COMPTABLE : Interdire la suppression si la caisse a déjà encaissé de l'argent
            if ((int) $subscription->amount_paid > 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Impossible de supprimer définitivement cet abonnement car un versement de " . number_format($subscription->amount_paid, 0, '', ' ') . " FCFA a déjà été encaissé. Veuillez plutôt modifier le statut de l'élève en 'Inactif' ou 'Suspendu'."
                ], 422);
            }

            // 2. Suppression physique en base de données si aucun flux financier n'est enregistré
            $subscription->delete();

            return response()->json([
                'status'  => 'success',
                'message' => "La fiche d'abonnement de transport de l'élève a été retirée avec succès."
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Dossier d’abonnement de transport introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors du retrait de l’abonnement de transport.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Enregistre un versement pour les frais de transport (Guichet Caisse Transport).
     * URL: POST /api/transport-subscriptions/{id}/pay
     */
    public function collectTransportPayment(Request $request, string $id)
    {   
         assert_writable_year();
        // 1. Validation du montant versé en Franc CFA (entier positif)
        $validated = $request->validate([
            'amount_to_pay' => ['required', 'integer', 'min:500'], // Minimum 500 FCFA par versement
        ]);

        try {
            $subscription = TransportSubscription::findOrFail($id);

            // 2. Calcul des verrous financiers en base de données
            $totalDue = (int) $subscription->total_amount;
            $currentPaid = (int) $subscription->amount_paid;
            $remaining = max(0, $totalDue - $currentPaid);
            $newAmount = (int) $validated['amount_to_pay'];

            // SÉCURITÉ COMPTABLE : Bloquer si le versement dépasse la dette transport de l'élève
            if ($newAmount > $remaining) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Erreur de caisse : Le montant saisi (" . number_format($newAmount, 0, '', ' ') . " FCFA) excède le reste à payer de l'élève (" . number_format($remaining, 0, '', ' ') . " FCFA)."
                ], 422);
            }

            // 3. Application comptable sécurisée dans une transaction SQL
            return DB::transaction(function () use ($subscription, $currentPaid, $newAmount, $totalDue) {
                
                // Calcul du nouveau cumul payé
                $updatedPaid = $currentPaid + $newAmount;
                
                // Recalcul du statut de recouvrement de la carte de bus
                if ($updatedPaid >= $totalDue) {
                    $paymentStatus = 'paid';
                } else {
                    $paymentStatus = 'partial';
                }

                // Mise à jour de la fiche d'abonnement transport
                $subscription->update([
                    'amount_paid'    => $updatedPaid,
                    'payment_status' => $paymentStatus
                ]);

                return response()->json([
                    'status'  => 'success',
                    'message' => "Versement de " . number_format($newAmount, 0, '', ' ') . " FCFA enregistré avec succès. La carte de bus de l'élève a été créditée."
                ], 200);
            });

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Abonnement de transport introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la validation du versement au guichet transport.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupère le grand livre de toutes les charges opérationnelles du parc automobile (Mois en cours).
     * URL: GET /api/vehicle-expenses
     */
    public function indexExpenses()
    {
        try {
            // 1. Récupération des limites temporelles du mois pour le filtrage analytique
            $startOfMonth = Carbon::now()->startOfMonth()->format('Y-m-d');
            $endOfMonth = Carbon::now()->endOfMonth()->format('Y-m-d');

            // 2. Extraction des dépenses avec les relations bus et créateur
            $expenses = VehicleExpense::with(['vehicle', 'creator'])
                ->whereBetween('expense_date', [$startOfMonth, $endOfMonth])
                ->orderBy('expense_date', 'desc')
                ->orderBy('id', 'desc')
                ->get();

            // 3. Reformatage à plat pour simplifier l'intégration dans le tableau React
            $formatted = $expenses->map(function ($exp) {
                return [
                    'id'                   => $exp->id,
                    'title'                => $exp->title, // ex: Facture Gasoil 50 Litres
                    'amount'               => (int) $exp->amount, // Forçage en entier pour le FCFA strict
                    'category'             => $exp->category, // fuel, washing, repair, insurance, salary...
                    'expense_date'         => $exp->expense_date ? $exp->expense_date->format('Y-m-d') : null,
                    'description'          => $exp->description,
                    
                    // Métadonnées du véhicule rattaché
                    'vehicle_id'           => $exp->vehicle_id,
                    'vehicle_name'         => $exp->vehicle ? $exp->vehicle->name : 'Bus inconnu',
                    'vehicle_registration' => $exp->vehicle ? $exp->vehicle->registration_number : 'N/A',
                    
                    // Traçabilité de l'agent de saisie
                    'author_name'          => $exp->creator ? $exp->creator->name : 'Système',
                ];
            });

            return response()->json($formatted, 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors du chargement du journal des charges du transport.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Enregistre une nouvelle charge (carburant, lavage, réparation, salaire) pour un véhicule.
     * URL: POST /api/vehicle-expenses
     */
    public function storeExpense(Request $request)
    {
        // 1. Validation stricte du flux de sortie de caisse (Catégories du cahier des charges)
        $validated = $request->validate([
            'vehicle_id'   => ['required', 'exists:vehicles,id'],
            'title'        => ['required', 'string', 'max:150'],
            'amount'       => ['required', 'integer', 'min:100'], // Minimum 100 FCFA
            'category'     => ['required', 'in:fuel,washing,repair,insurance,salary,other'],
            'expense_date' => ['required', 'date'],
            'description'  => ['nullable', 'string'],
        ]);

        try {
            // 2. Création de la ligne budgétaire de charge liée au transport
            $expense = VehicleExpense::create([
                'vehicle_id'   => (int) $validated['vehicle_id'],
                'title'        => trim($validated['title']),
                'amount'       => (int) $validated['amount'],
                'category'     => $validated['category'],
                'expense_date' => $validated['expense_date'],
                'description'  => $validated['description'] ?? null,
                'created_by'   => Auth::id() ?? null, // Traçabilité immédiate du caissier/comptable
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => "La dépense de " . number_format($expense->amount, 0, '', ' ') . " FCFA pour '" . $expense->title . "' a été validée et enregistrée sur le véhicule.",
                'expense' => $expense
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Données de facturation ou de catégorie incorrectes.',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur imprévue est survenue lors du décaissement transport.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Modifie une ligne de charge du parc automobile existante.
     * URL: PUT /api/vehicle-expenses/{id}
     */
    public function updateExpense(Request $request, string $id)
    {
        try {
            $expense = VehicleExpense::findOrFail($id);

            // 1. Validation stricte des données modifiées (FCFA strict entier positif)
            $validated = $request->validate([
                'vehicle_id'   => ['required', 'exists:vehicles,id'],
                'title'        => ['required', 'string', 'max:150'],
                'amount'       => ['required', 'integer', 'min:100'],
                'category'     => ['required', 'in:fuel,washing,repair,insurance,salary,other'],
                'expense_date' => ['required', 'date'],
                'description'  => ['nullable', 'string'],
            ]);

            // 2. Application de la mise à jour avec la syntaxe PHP native
            $expense->update([
                'vehicle_id'   => (int) $validated['vehicle_id'],
                'title'        => trim($validated['title']),
                'amount'       => (int) $validated['amount'],
                'category'     => $validated['category'],
                'expense_date' => $validated['expense_date'],
                'description'  => $validated['description'] ?? null,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'L’écriture comptable de la charge a été rectifiée avec succès.'
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Les informations fournies sont incorrectes.',
                'errors'  => $e->errors()
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Ligne de charge introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur technique est survenue lors de la correction de la dépense.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Annule et supprime définitivement une écriture de charge du parc automobile.
     * URL: DELETE /api/vehicle-expenses/{id}
     */
    public function destroyExpense(string $id)
    {
        try {
            // 1. Recherche de la ligne de dépense transport
            $expense = VehicleExpense::findOrFail($id);

            // 2. Suppression physique du décaissement
            $expense->delete();

            return response()->json([
                'status'  => 'success',
                'message' => 'L’écriture de charge du garage a été annulée et retirée du grand livre avec succès.'
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Cette ligne de charge n’existe pas ou a déjà été annulée.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur technique est survenue lors de l’annulation de la dépense.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }




}
