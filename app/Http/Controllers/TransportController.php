<?php

namespace App\Http\Controllers;

use App\Models\Academic\AcademicYears;
use App\Models\Personel\Employee;
use App\Models\Transport\TransportPayment;
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
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\TransportReportExport;

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

            // 2. ─── CORRECTIF : Recettes RÉELLEMENT encaissées ce mois-ci,
            // calculées depuis le journal des versements (transport_payments)
            // par DATE DE VERSEMENT — pas depuis la date de création de
            // l'abonnement. Avant ce correctif, un versement complété en
            // août sur un abonnement créé en juillet n'apparaissait JAMAIS
            // dans les recettes d'août : le total du mois était faux dès
            // qu'un versement partiel était complété plus tard.
            $totalReceipts = TransportPayment::whereBetween('payment_date', [$startOfMonth, $endOfMonth])
                ->sum('amount_paid');

            // 3. Ventilation analytique détaillée des dépenses (FCFA)
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

            // ─── CORRECTIF : catégories manquantes du bilan. Une dépense
            // d'assurance ou "autre" était bien enregistrée dans le journal
            // des charges, mais disparaissait silencieusement du calcul de
            // rentabilité — le bilan montré à la direction était
            // artificiellement plus optimiste que la réalité.
            $insuranceExpenses = VehicleExpense::where('category', 'insurance')
                ->whereBetween('expense_date', [$startOfMonth, $endOfMonth])
                ->sum('amount');

            $otherExpenses = VehicleExpense::where('category', 'other')
                ->whereBetween('expense_date', [$startOfMonth, $endOfMonth])
                ->sum('amount');

            // 4. Calcul des totaux et du solde net de rentabilité (Recettes - Dépenses)
            $totalReceiptsInt = (int) $totalReceipts;

            $totalExpenses = (int) ($fuelExpenses + $washingExpenses + $maintenanceExpenses + $salaryExpenses + $insuranceExpenses + $otherExpenses);
            $netBalance = $totalReceiptsInt - $totalExpenses;
            $isProfit = $netBalance >= 0;

            // 5. PILOTAGE ANALYTIQUE : Coût réel du transport par élève
            $realCostPerStudent = $totalStudents > 0 ? round($totalExpenses / $totalStudents) : 0;

            // 6. Envoi de la réponse structurée lue par TransportDashboardPage.jsx
            return response()->json([
                'total_students_transported' => (int) $totalStudents,
                'total_receipts'             => $totalReceiptsInt,
                'fuel_expenses'              => (int) $fuelExpenses,
                'washing_expenses'           => (int) $washingExpenses,
                'maintenance_expenses'       => (int) $maintenanceExpenses,
                'salary_expenses'            => (int) $salaryExpenses,
                'insurance_expenses'         => (int) $insuranceExpenses,
                'other_expenses'             => (int) $otherExpenses,
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
            $vehicles = Vehicle::with(['driver'])
                ->orderBy('id', 'desc')
                ->get();

            $formatted = $vehicles->map(function ($veh) {
                $driver = $veh->driver;

                return [
                    'id'                  => $veh->id,
                    'name'                => $veh->name,
                    'registration_number' => $veh->registration_number,
                    'brand'               => $veh->brand,
                    'model'               => $veh->model,
                    'capacity'            => (int) $veh->capacity,
                    'purchase_date'       => $veh->purchase_date ? $veh->purchase_date->format('Y-m-d') : null,
                    'is_active'           => (bool) $veh->is_active,
                    'driver_id'           => $veh->driver_id,

                    'driver' => $driver ? [
                        'id'         => $driver->id,
                        'first_name' => $driver->first_name,
                        'last_name'  => $driver->last_name,
                        'phone'      => $driver->phone,
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
        $validated = $request->validate([
            'name'                => ['required', 'string', 'max:100'],
            'registration_number' => ['required', 'string', 'max:50', 'unique:vehicles,registration_number'],
            'brand'               => ['nullable', 'string', 'max:100'],
            'model'               => ['nullable', 'string', 'max:100'],
            'capacity'            => ['required', 'integer', 'min:1'],
            'purchase_date'       => ['nullable', 'date'],
            'driver_id'           => ['nullable', 'exists:employees,id'],
        ]);

        try {
            $validated['registration_number'] = strtoupper(trim($validated['registration_number']));
            $validated['is_active'] = true;

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
            $vehicle = Vehicle::with([
                'driver',
                'routes',
                'expenses' => function ($query) {
                    $query->orderBy('expense_date', 'desc')->take(20);
                }
            ])->findOrFail($id);

            $formatted = [
                'id'                  => $vehicle->id,
                'name'                => $vehicle->name,
                'registration_number' => $vehicle->registration_number,
                'brand'               => $vehicle->brand,
                'model'               => $vehicle->model,
                'capacity'            => (int) $vehicle->capacity,
                'purchase_date'       => $vehicle->purchase_date ? $vehicle->purchase_date->format('Y-m-d') : null,
                'is_active'           => (bool) $vehicle->is_active,

                'driver' => $vehicle->driver ? [
                    'id'         => $vehicle->driver->id,
                    'full_name'  => $vehicle->driver->first_name . ' ' . $vehicle->driver->last_name,
                    'phone'      => $vehicle->driver->phone,
                ] : null,

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

                'expenses' => $vehicle->expenses->map(function ($exp) {
                    return [
                        'id'           => $exp->id,
                        'title'        => $exp->title,
                        'amount'       => (int) $exp->amount,
                        'category'     => $exp->category,
                        'expense_date' => $exp->expense_date ? $exp->expense_date->format('Y-m-d') : null,
                    ];
                }),
                
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

            $validated = $request->validate([
                'name'                => ['required', 'string', 'max:100'],
                'registration_number' => ['required', 'string', 'max:50', Rule::unique('vehicles', 'registration_number')->ignore($id)],
                'brand'               => ['nullable', 'string', 'max:100'],
                'model'               => ['nullable', 'string', 'max:100'],
                'capacity'            => ['required', 'integer', 'min:1'],
                'purchase_date'       => ['nullable', 'date'],
                'driver_id'           => ['nullable', 'exists:employees,id'],
                'is_active'           => ['required', 'boolean'],
            ]);

            $validated['registration_number'] = strtoupper(trim($validated['registration_number']));

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
            $vehicle = Vehicle::withCount('routes')->findOrFail($id);

            if ($vehicle->routes_count > 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Impossible de supprimer ce véhicule car " . $vehicle->routes_count . " ligne(s) de bus active(s) l'utilisent actuellement pour le ramassage. Veuillez plutôt passer son état à 'Garage' en décochant la case 'Disponible'."
                ], 422);
            }

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
            $drivers = Employee::with('position')
                ->whereHas('position', function($query) {
                    $query->where('slug', 'chauffeur')
                        ->orWhere('name', 'LIKE', '%chauffeur%')
                        ->orWhere('name', 'LIKE', '%conducteur%');
                })
                ->where('status', 'active')
                ->orderBy('last_name', 'asc')
                ->get();

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
            $routes = TransportRoute::with(['vehicle'])
                ->orderBy('name', 'asc')
                ->get();

            $formatted = $routes->map(function ($route) {
                return [
                    'id'              => $route->id,
                    'vehicle_id'      => $route->vehicle_id,
                    'name'            => $route->name,
                    'departure_point' => $route->departure_point,
                    'arrival_point'   => $route->arrival_point,
                    'stops_circuit'   => $route->stops_circuit,
                    'monthly_fee'     => (int) $route->monthly_fee,
                    'is_active'       => (bool) $route->is_active,

                    'vehicle' => $route->vehicle ? [
                        'id'                  => $route->vehicle->id,
                        'name'                => $route->vehicle->name,
                        'registration_number' => $route->vehicle->registration_number,
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
        $validated = $request->validate([
            'vehicle_id'      => ['required', 'exists:vehicles,id'],
            'name'            => ['required', 'string', 'max:150'],
            'departure_point' => ['required', 'string', 'max:150'],
            'arrival_point'   => ['required', 'string', 'max:150'],
            'stops_circuit'   => ['nullable', 'string'],
            'monthly_fee'     => ['required', 'integer', 'min:0'],
        ]);

        try {
            $validated['is_active'] = true;

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
            $route = TransportRoute::with([
                'vehicle.driver',
                'subscriptions.student.classe'
            ])->findOrFail($id);

            $formatted = [
                'id'              => $route->id,
                'name'            => $route->name,
                'departure_point' => $route->departure_point,
                'arrival_point'   => $route->arrival_point,
                'stops_circuit'   => $route->stops_circuit,
                'monthly_fee'     => (int) $route->monthly_fee,
                'is_active'       => (bool) $route->is_active,

                'vehicle' => $route->vehicle ? [
                    'id'                  => $route->vehicle->id,
                    'name'                => $route->vehicle->name,
                    'registration_number' => $route->vehicle->registration_number,
                    'driver_name'         => $route->vehicle->driver 
                        ? $route->vehicle->driver->first_name . ' ' . $route->vehicle->driver->last_name 
                        : 'Aucun chauffeur assigné',
                ] : null,

                'subscribers' => $route->subscriptions->map(function ($sub) {
                    $student = $sub->student;
                    return [
                        'subscription_id' => $sub->id,
                        'student_id'      => $student ? $student->id : null,
                        'matricule'       => $student ? $student->matricule : 'N/A',
                        'full_name'       => $student ? $student->first_name . ' ' . $student->last_name : 'Élève inconnu',
                        'class_name'      => ($student && $student->classe) ? $student->classe->name : 'N/A',
                        'status'          => $sub->status,
                        'payment_status'  => $sub->payment_status
                    ];
                }),
                
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

            $validated = $request->validate([
                'vehicle_id'      => ['required', 'exists:vehicles,id'],
                'name'            => ['required', 'string', 'max:150'],
                'departure_point' => ['required', 'string', 'max:150'],
                'arrival_point'   => ['required', 'string', 'max:150'],
                'stops_circuit'   => ['nullable', 'string'],
                'monthly_fee'     => ['required', 'integer', 'min:0'],
                'is_active'       => ['required', 'boolean'],
            ]);

            $route->update([
                'vehicle_id'      => (int) $validated['vehicle_id'],
                'name'            => trim($validated['name']),
                'departure_point' => trim($validated['departure_point']),
                'arrival_point'   => trim($validated['arrival_point']),
                'stops_circuit'   => $validated['stops_circuit'] ? trim($validated['stops_circuit']) : null,
                'monthly_fee'     => (int) $validated['monthly_fee'],
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
            $route = TransportRoute::withCount('subscriptions')->findOrFail($id);

            if ($route->subscriptions_count > 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Impossible de supprimer cette ligne car " . $route->subscriptions_count . " élève(s) y sont actuellement inscrit(s). Veuillez plutôt la fermer en décochant la case 'Ligne ouverte aux inscriptions' depuis le configurateur."
                ], 422);
            }

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
        $todayStr = \Illuminate\Support\Carbon::today()->format('Y-m-d');
        
        TransportSubscription::where('status', 'active')
            ->where('end_date', '<', $todayStr)
            ->update(['status' => 'inactive']);

        // ─── AJOUT : charge aussi le journal des versements de chaque
        // abonnement, pour alimenter la fenêtre "Reçus & Versements" du
        // frontend avec les VRAIES données au lieu du repli factice.
        $subscriptions = TransportSubscription::with(['student.classe', 'route', 'payments'])
            ->latest()
            ->get();

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
                // ─── CORRECTIF : "monthly_fee" manquait ici — c'est
                // exactement ce qui alimente l'auto-remplissage du prix
                // dans la modale "Renouveler". Sans lui, le prix restait
                // toujours vide (affiché à 0), et comme le champ n'est
                // plus modifiable manuellement, le renouvellement
                // devenait bloqué à tort.
                'route' => $sub->route ? [
                    'id'          => $sub->route->id,
                    'name'        => $sub->route->name,
                    'monthly_fee' => (int) $sub->route->monthly_fee,
                ] : null,
                // ─── AJOUT : journal des versements, format attendu tel
                // quel par TransportSubscriptionsPage.jsx (fenêtre reçus +
                // ticket imprimable).
                'payments' => $sub->payments->map(function ($p) {
                    return [
                        'id'          => $p->id,
                        'amount_paid' => (int) $p->amount_paid,
                        'created_at'  => $p->created_at, // CORRECTIF : vraie heure du versement, plus de T00:00:00 forcé
                        'reference'   => $p->receipt_number,
                    ];
                }),
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
            // ─── CORRECTIF : l'année scolaire active doit être celle de
            // L'ÉTABLISSEMENT ACTUELLEMENT CONSULTÉ, pas la première
            // trouvée dans TOUTE la base. Sans ce filtre, sur un groupe
            // scolaire ou plusieurs clients partageant la même base, cette
            // requête pouvait piocher l'année active d'un AUTRE
            // établissement — un bug déjà rencontré et corrigé ailleurs
            // dans l'application cette session.
            $activeYearId = AcademicYears::where('establishment_id', current_establishment_id())
                ->where('is_active', 1)
                ->value('id');

            if (!$activeYearId) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Opération impossible : Aucune année académique n'est active actuellement sur le système."
                ], 422);
            }

            $exists = TransportSubscription::where('student_id', $validated['student_id'])
                ->where('academic_year_id', $activeYearId)
                ->exists();

            if ($exists) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Cet élève dispose déjà d'un dossier d'abonnement actif pour le transport sur cette année scolaire."
                ], 422);
            }

            $total = (int) $validated['total_amount'];
            $paid  = (int) $validated['amount_paid'];

            if ($paid >= $total) {
                $paymentStatus = 'paid';
            } elseif ($paid > 0 && $paid < $total) {
                $paymentStatus = 'partial';
            } else {
                $paymentStatus = 'unpaid';
            }

            // ─── Transaction : la création de l'abonnement ET l'écriture
            // du premier versement dans le journal doivent réussir
            // ensemble, ou pas du tout.
            $subscription = DB::transaction(function () use ($validated, $activeYearId, $total, $paid, $paymentStatus) {
                $sub = TransportSubscription::create([
                    'student_id'       => $validated['student_id'],
                    'academic_year_id' => $activeYearId,
                    'route_id'         => (int) $validated['route_id'],
                    'start_date'       => $validated['start_date'],
                    'end_date'         => $validated['end_date'],
                    'total_amount'     => $total,
                    'amount_paid'      => $paid,
                    'status'           => 'active',
                    'payment_status'   => $paymentStatus,
                    'notes'            => $validated['notes'] ?? null,
                ]);

                // ─── AJOUT : si un acompte de départ est versé à la
                // création, il doit lui aussi apparaître dans le journal
                // des versements (sinon ce premier montant resterait
                // invisible dans l'historique et les reçus).
                if ($paid > 0) {
                    TransportPayment::create([
                        'subscription_id' => $sub->id,
                        'receipt_number'  => static::generateTransportReceiptNumber(),
                        'amount_paid'     => $paid,
                        'payment_date'    => $validated['start_date'],
                        'created_by'      => Auth::id() ?? null,
                    ]);
                }

                return $sub;
            });

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
            $subscription = TransportSubscription::with([
                'student.classe', 
                'route.vehicle.driver',
                'payments',
            ])->findOrFail($id);

            $student = $subscription->student;
            $route = $subscription->route;

            $formatted = [
                'id'             => $subscription->id,
                'start_date'     => $subscription->start_date ? $subscription->start_date->format('Y-m-d') : null,
                'end_date'       => $subscription->end_date ? $subscription->end_date->format('Y-m-d') : null,
                'status'         => $subscription->status,
                'payment_status' => $subscription->payment_status,
                'notes'          => $subscription->notes,
                
                'total_amount'   => (int) $subscription->total_amount,
                'amount_paid'    => (int) $subscription->amount_paid,
                'remaining'      => (int) max(0, $subscription->total_amount - $subscription->amount_paid),

                'student' => $student ? [
                    'id'          => $student->id,
                    'matricule'   => $student->matricule,
                    'first_name'  => $student->first_name,
                    'last_name'   => $student->last_name,
                    'gender'      => $student->gender,
                    'photo'       => $student->photo,
                    'class_name'  => $student->classe ? $student->classe->name : 'N/A',
                ] : null,

                'route' => $route ? [
                    'id'              => $route->id,
                    'name'            => $route->name,
                    'monthly_fee'     => (int) $route->monthly_fee,
                    'departure_point' => $route->departure_point,
                    'arrival_point'   => $route->arrival_point,
                    'stops_circuit'   => $route->stops_circuit,
                    
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

                // ─── AJOUT : journal des versements ───
                'payments' => $subscription->payments->map(function ($p) {
                    return [
                        'id'          => $p->id,
                        'amount_paid' => (int) $p->amount_paid,
                        'created_at'  => $p->created_at, // CORRECTIF : vraie heure du versement, plus de T00:00:00 forcé
                        'reference'   => $p->receipt_number,
                    ];
                }),
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

            $validated = $request->validate([
                'route_id'     => ['required', 'exists:transport_routes,id'],
                'start_date'   => ['required', 'date'],
                'end_date'     => ['required', 'date', 'after_or_equal:start_date'],
                'total_amount' => ['required', 'integer', 'min:0'],
                'status'       => ['required', 'in:active,inactive,suspended'],
                'notes'        => ['nullable', 'string'],
            ]);

            $total = (int) $validated['total_amount'];
            $paid  = (int) $subscription->amount_paid;

            if ($paid >= $total) {
                $paymentStatus = 'paid';
            } elseif ($paid > 0 && $paid < $total) {
                $paymentStatus = 'partial';
            } else {
                $paymentStatus = 'unpaid';
            }

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

            // ─── CORRECTIF : bloque désormais la suppression tant que le
            // solde n'est pas ENTIÈREMENT réglé (payment_status !== 'paid'),
            // pas seulement "un premier versement existe". Avant ce
            // correctif, un abonnement encore partiellement dû pouvait
            // être supprimé dès que amount_paid était exactement 0 —
            // effaçant une dette réelle sans laisser aucune trace pour
            // l'audit. Le bouton "Supprimer" reste désormais actif
            // uniquement une fois la carte totalement soldée.
            if ($subscription->payment_status !== 'paid') {
                $remaining = max(0, (int) $subscription->total_amount - (int) $subscription->amount_paid);
                return response()->json([
                    'status'  => 'error',
                    'message' => "Impossible de supprimer cet abonnement : le solde n'est pas encore intégralement réglé (reste à payer : " . number_format($remaining, 0, '', ' ') . " FCFA). Réglez d'abord le montant dû, ou changez le statut en 'Inactif' plutôt que de supprimer."
                ], 422);
            }

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
        $validated = $request->validate([
            'amount_to_pay' => ['required', 'integer', 'min:500'],
        ]);

        try {
            $subscription = TransportSubscription::findOrFail($id);

            $totalDue = (int) $subscription->total_amount;
            $currentPaid = (int) $subscription->amount_paid;
            $remaining = max(0, $totalDue - $currentPaid);
            $newAmount = (int) $validated['amount_to_pay'];

            if ($newAmount > $remaining) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Erreur de caisse : Le montant saisi (" . number_format($newAmount, 0, '', ' ') . " FCFA) excède le reste à payer de l'élève (" . number_format($remaining, 0, '', ' ') . " FCFA)."
                ], 422);
            }

            return DB::transaction(function () use ($subscription, $currentPaid, $newAmount, $totalDue) {
                
                $updatedPaid = $currentPaid + $newAmount;
                
                if ($updatedPaid >= $totalDue) {
                    $paymentStatus = 'paid';
                } else {
                    $paymentStatus = 'partial';
                }

                $subscription->update([
                    'amount_paid'    => $updatedPaid,
                    'payment_status' => $paymentStatus
                ]);

                // ─── AJOUT : chaque versement devient sa propre ligne
                // dans le journal, avec un numéro de reçu unique — c'est
                // CE journal (pas le total cumulé sur l'abonnement) qui
                // sert désormais de base aux reçus imprimables et à
                // l'historique, exactement comme le système de paiements
                // de la scolarité.
                $receiptNumber = static::generateTransportReceiptNumber();

                $payment = TransportPayment::create([
                    'subscription_id' => $subscription->id,
                    'receipt_number'  => $receiptNumber,
                    'amount_paid'     => $newAmount,
                    'payment_date'    => now()->format('Y-m-d'),
                    'created_by'      => Auth::id() ?? null,
                ]);

                return response()->json([
                    'status'  => 'success',
                    'message' => "Versement de " . number_format($newAmount, 0, '', ' ') . " FCFA enregistré avec succès sous le reçu {$receiptNumber}.",
                    'receipt_number' => $receiptNumber,
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
     * ─── AJOUT : Renouvellement d'un abonnement pour une nouvelle période
     * — même principe que le module Cantine : on ne demande QUE le prix de
     * la nouvelle période (jamais un total cumulé à recalculer à la main),
     * le backend fait l'addition lui-même. Élimine tout risque d'erreur de
     * saisie ou de confusion sur "faut-il remettre le total ou juste le
     * complément ?".
     * URL: POST /api/transport-subscriptions/{id}/renew
     */
    public function renewSubscription(Request $request, string $id)
    {
        assert_writable_year();

        $validated = $request->validate([
            'period_amount' => ['required', 'integer', 'min:0'],
            'new_end_date'  => ['required', 'date'],
            'payment_now'   => ['nullable', 'integer', 'min:0'],
            'notes'         => ['nullable', 'string'],
        ]);

        try {
            $subscription = TransportSubscription::findOrFail($id);

            return DB::transaction(function () use ($subscription, $validated) {
                $periodAmount = (int) $validated['period_amount'];
                $paymentNow = (int) ($validated['payment_now'] ?? 0);

                $newTotal = (int) $subscription->total_amount + $periodAmount;
                $newPaid = (int) $subscription->amount_paid + $paymentNow;

                if ($newPaid >= $newTotal) {
                    $paymentStatus = 'paid';
                } elseif ($newPaid > 0) {
                    $paymentStatus = 'partial';
                } else {
                    $paymentStatus = 'unpaid';
                }

                $subscription->update([
                    'total_amount'   => $newTotal,
                    'amount_paid'    => $newPaid,
                    'end_date'       => $validated['new_end_date'],
                    'status'         => 'active', // Une reprise de service après renouvellement redevient active
                    'payment_status' => $paymentStatus,
                    'notes'          => $validated['notes'] ?? $subscription->notes,
                ]);

                // ─── CORRECTIF : une ligne est désormais TOUJOURS créée
                // dans le journal au moment du renouvellement — même à
                // 0 FCFA si aucun versement immédiat n'est fait. Avant ce
                // correctif, renouveler sans payer ne laissait AUCUNE
                // trace de l'événement : impossible de savoir quand la
                // période avait été prolongée, le reçu affichait toujours
                // la date du tout premier versement. "payment_method"
                // permet de distinguer clairement un renouvellement (même
                // sans argent) d'un vrai encaissement en caisse.
                TransportPayment::create([
                    'subscription_id' => $subscription->id,
                    'receipt_number'  => static::generateTransportReceiptNumber(),
                    'amount_paid'     => $paymentNow, // Peut être 0 — trace l'événement quand même
                    'payment_date'    => now()->format('Y-m-d'),
                    'payment_method'  => $paymentNow > 0 ? 'cash' : 'renewal',
                    'notes'           => "Renouvellement — nouvelle période jusqu'au " . \Carbon\Carbon::parse($validated['new_end_date'])->format('d/m/Y') . (($paymentNow > 0) ? '' : ' (aucun versement à cette date)'),
                    'created_by'      => Auth::id() ?? null,
                ]);

                return response()->json([
                    'status'  => 'success',
                    'message' => "Abonnement renouvelé avec succès jusqu'au " . \Carbon\Carbon::parse($validated['new_end_date'])->format('d/m/Y') . ".",
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
                'message' => 'Une erreur est survenue lors du renouvellement de l’abonnement.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ─── AJOUT : Génère le PDF de la carte de transport d'un élève —
     * c'est CETTE route que le QR code de la carte encode. Volontairement
     * PUBLIQUE (aucune authentification) : la personne qui scanne (parent,
     * chauffeur, agent de sécurité au portail) n'a pas de compte sur la
     * plateforme. Les informations exposées sont strictement celles déjà
     * visibles sur la carte physique elle-même — rien de plus sensible.
     * URL: GET /public/transport-card/{id}/pdf (hors groupe auth:sanctum)
     */
    public function downloadCardPdf(string $id)
    {
        try {
            $subscription = TransportSubscription::with(['student', 'route'])->findOrFail($id);

            // L'établissement est déduit de l'abonnement lui-même (pas de
            // current_establishment_id() ici puisque la route est publique
            // et n'a donc pas de "contexte" de connexion pour la déterminer).
            $establishment = \App\Models\Establishment::find($subscription->establishment_id);

            $pdf = Pdf::loadView('pdf.transport-card-pdf', [
                'subscription'      => $subscription,
                'establishmentName' => $establishment->name ?? 'Établissement scolaire',
            ]);

            $fileName = 'carte-transport-' . ($subscription->student->matricule ?? $subscription->id) . '.pdf';

            return $pdf->stream($fileName);

        } catch (ModelNotFoundException $e) {
            abort(404, 'Carte de transport introuvable.');
        } catch (\Exception $e) {
            abort(500, 'Erreur lors de la génération du document.');
        }
    }

    /**
     * ─── AJOUT : Historique global de TOUS les versements de transport,
     * tous élèves confondus — pour l'audit et le contrôle comptable
     * d'ensemble, en miroir de "Historique des reçus" côté scolarité.
     * URL: GET /api/transport/payments/receipts
     */
    public function transportReceiptsHistory(Request $request)
    {
        try {
            $query = TransportPayment::with(['subscription.student.classe', 'subscription.route', 'creator']);

            if ($search = $request->query('q')) {
                $query->whereHas('subscription.student', function ($q) use ($search) {
                    $q->where('first_name', 'LIKE', "%{$search}%")
                      ->orWhere('last_name', 'LIKE', "%{$search}%")
                      ->orWhere('matricule', 'LIKE', "%{$search}%");
                });
            }

            if ($from = $request->query('from')) {
                $query->where('payment_date', '>=', $from);
            }
            if ($to = $request->query('to')) {
                $query->where('payment_date', '<=', $to);
            }

            $payments = $query->orderByDesc('payment_date')->orderByDesc('id')->paginate(30);

            $formatted = collect($payments->items())->map(function ($p) {
                $student = $p->subscription?->student;
                return [
                    'id'             => $p->id,
                    'receipt_number' => $p->receipt_number,
                    'amount_paid'    => (int) $p->amount_paid,
                    'payment_date'   => $p->payment_date ? $p->payment_date->format('Y-m-d') : null,
                    'student_name'   => $student ? "{$student->first_name} {$student->last_name}" : 'Élève inconnu',
                    'matricule'      => $student?->matricule,
                    'class_name'     => $student?->classe?->name,
                    'route_name'     => $p->subscription?->route?->name,
                    'created_by'     => $p->creator?->name,
                ];
            });

            return response()->json([
                'status'      => 'success',
                'data'        => $formatted,
                'total'       => $payments->total(),
                'last_page'   => $payments->lastPage(),
                'current_page'=> $payments->currentPage(),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors du chargement de l’historique des reçus transport.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ─── AJOUT : génère un numéro de reçu unique, préfixé par
     * établissement — même principe que les reçus de scolarité, mais avec
     * le préfixe "TR-" pour distinguer immédiatement un reçu transport
     * d'un reçu de scolarité lors d'un audit.
     */
    private static function generateTransportReceiptNumber(): string
    {
        $estabPrefix = current_establishment_prefix();
        $year = date('Y');
        $prefix = "TR-{$estabPrefix}-{$year}-";

        $last = TransportPayment::whereRaw("receipt_number LIKE '{$prefix}%'")->latest('id')->first();
        $next = $last ? ((int) substr($last->receipt_number, -5)) + 1 : 1;

        return $prefix . str_pad($next, 5, '0', STR_PAD_LEFT);
    }


    /**
     * Récupère le grand livre de toutes les charges opérationnelles du parc automobile (Mois en cours).
     * URL: GET /api/vehicle-expenses
     */
    public function indexExpenses()
    {
        try {
            $startOfMonth = Carbon::now()->startOfMonth()->format('Y-m-d');
            $endOfMonth = Carbon::now()->endOfMonth()->format('Y-m-d');

            $expenses = VehicleExpense::with(['vehicle', 'creator'])
                ->whereBetween('expense_date', [$startOfMonth, $endOfMonth])
                ->orderBy('expense_date', 'desc')
                ->orderBy('id', 'desc')
                ->get();

            $formatted = $expenses->map(function ($exp) {
                return [
                    'id'                   => $exp->id,
                    'title'                => $exp->title,
                    'amount'               => (int) $exp->amount,
                    'category'             => $exp->category,
                    'expense_date'         => $exp->expense_date ? $exp->expense_date->format('Y-m-d') : null,
                    'description'          => $exp->description,
                    
                    'vehicle_id'           => $exp->vehicle_id,
                    'vehicle_name'         => $exp->vehicle ? $exp->vehicle->name : 'Bus inconnu',
                    'vehicle_registration' => $exp->vehicle ? $exp->vehicle->registration_number : 'N/A',
                    
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
        $validated = $request->validate([
            'vehicle_id'   => ['required', 'exists:vehicles,id'],
            'title'        => ['required', 'string', 'max:150'],
            'amount'       => ['required', 'integer', 'min:100'],
            'category'     => ['required', 'in:fuel,washing,repair,insurance,salary,other'],
            'expense_date' => ['required', 'date'],
            'description'  => ['nullable', 'string'],
        ]);

        try {
            $expense = VehicleExpense::create([
                'vehicle_id'   => (int) $validated['vehicle_id'],
                'title'        => trim($validated['title']),
                'amount'       => (int) $validated['amount'],
                'category'     => $validated['category'],
                'expense_date' => $validated['expense_date'],
                'description'  => $validated['description'] ?? null,
                'created_by'   => Auth::id() ?? null,
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

            $validated = $request->validate([
                'vehicle_id'   => ['required', 'exists:vehicles,id'],
                'title'        => ['required', 'string', 'max:150'],
                'amount'       => ['required', 'integer', 'min:100'],
                'category'     => ['required', 'in:fuel,washing,repair,insurance,salary,other'],
                'expense_date' => ['required', 'date'],
                'description'  => ['nullable', 'string'],
            ]);

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
            $expense = VehicleExpense::findOrFail($id);

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


    /**
     * ─── AJOUT : Calcule toutes les sections du rapport financier
     * Transport sur une période donnée — réutilisé par les 3 formats de
     * sortie (JSON pour l'écran, PDF, Excel) pour ne jamais dupliquer la
     * logique de calcul entre les trois.
     */
    private function computeTransportReportData(string $from, string $to): array
    {
        // ── A. Résumé financier de la période ──
        $totalReceipts = (int) TransportPayment::whereBetween('payment_date', [$from, $to])->sum('amount_paid');

        $expensesByCategory = VehicleExpense::whereBetween('expense_date', [$from, $to])
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->pluck('total', 'category');

        $categories = ['fuel', 'washing', 'repair', 'insurance', 'salary', 'other'];
        $expenses = [];
        $totalExpenses = 0;
        foreach ($categories as $cat) {
            $val = (int) ($expensesByCategory[$cat] ?? 0);
            $expenses[$cat] = $val;
            $totalExpenses += $val;
        }

        $netBalance = $totalReceipts - $totalExpenses;

        $summary = [
            'total_receipts' => $totalReceipts,
            'expenses'       => $expenses,
            'total_expenses' => $totalExpenses,
            'net_balance'    => $netBalance,
            'is_profit'      => $netBalance >= 0,
            'coverage_rate'  => $totalExpenses > 0 ? round(($totalReceipts / $totalExpenses) * 100) : 100,
        ];

        // ── B. Rentabilité par véhicule ──
        $vehicles = Vehicle::with('routes')->get()->map(function ($vehicle) use ($from, $to) {
            $vehicleExpenses = (int) VehicleExpense::where('vehicle_id', $vehicle->id)
                ->whereBetween('expense_date', [$from, $to])
                ->sum('amount');

            $routeIds = $vehicle->routes->pluck('id');

            $vehicleRevenue = (int) TransportPayment::whereBetween('payment_date', [$from, $to])
                ->whereHas('subscription', function ($q) use ($routeIds) {
                    $q->whereIn('route_id', $routeIds);
                })
                ->sum('amount_paid');

            $studentsCount = TransportSubscription::whereIn('route_id', $routeIds)
                ->where('status', 'active')
                ->count();

            return [
                'id'             => $vehicle->id,
                'name'           => $vehicle->name,
                'registration'   => $vehicle->registration_number,
                'students_count' => $studentsCount,
                'revenue'        => $vehicleRevenue,
                'expenses'       => $vehicleExpenses,
                'net'            => $vehicleRevenue - $vehicleExpenses,
            ];
        })->values();

        // ── C. Taux de recouvrement — état actuel, pas lié à la période
        // (une dette n'a pas de "fenêtre temporelle" propre, contrairement
        // aux recettes/charges déjà encaissées/payées) ──
        $allActive = TransportSubscription::where('status', 'active')->get();
        $recovery = [
            'paid_count'      => $allActive->where('payment_status', 'paid')->count(),
            'partial_count'   => $allActive->where('payment_status', 'partial')->count(),
            'unpaid_count'    => $allActive->where('payment_status', 'unpaid')->count(),
            'total_due'       => (int) $allActive->sum('total_amount'),
            'total_paid'      => (int) $allActive->sum('amount_paid'),
            'total_remaining' => (int) $allActive->sum(fn($s) => max(0, $s->total_amount - $s->amount_paid)),
        ];

        // ── D. Évolution mensuelle — 6 derniers mois glissants jusqu'à $to ──
        $monthlyTrend = [];
        $cursor = \Carbon\Carbon::parse($to)->startOfMonth();
        for ($i = 5; $i >= 0; $i--) {
            $monthStart = $cursor->copy()->subMonths($i);
            $monthEnd = $monthStart->copy()->endOfMonth();

            $monthlyTrend[] = [
                'label'    => ucfirst($monthStart->translatedFormat('M Y')),
                'receipts' => (int) TransportPayment::whereBetween('payment_date', [$monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')])->sum('amount_paid'),
                'expenses' => (int) VehicleExpense::whereBetween('expense_date', [$monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')])->sum('amount'),
            ];
        }

        // ── E. Liste des impayés — état actuel, tous les élèves avec un
        // solde restant, classés du plus gros débiteur au plus petit ──
        $unpaidList = TransportSubscription::with(['student.classe', 'route'])
            ->where('status', 'active')
            ->whereColumn('amount_paid', '<', 'total_amount')
            ->get()
            ->map(function ($sub) {
                $student = $sub->student;
                return [
                    'student_name' => $student ? "{$student->first_name} {$student->last_name}" : 'Élève inconnu',
                    'matricule'    => $student->matricule ?? '—',
                    'class_name'   => ($student && $student->classe) ? $student->classe->name : '—',
                    'route_name'   => $sub->route->name ?? '—',
                    'total_amount' => (int) $sub->total_amount,
                    'amount_paid'  => (int) $sub->amount_paid,
                    'remaining'    => (int) max(0, $sub->total_amount - $sub->amount_paid),
                ];
            })
            ->sortByDesc('remaining')
            ->values();

        return [
            'period'        => ['from' => $from, 'to' => $to],
            'summary'       => $summary,
            'vehicles'      => $vehicles,
            'recovery'      => $recovery,
            'monthly_trend' => $monthlyTrend,
            'unpaid_list'   => $unpaidList,
        ];
    }

    /**
     * ─── AJOUT : Rapport financier Transport — vue JSON pour l'écran.
     * URL: GET /api/transport/reports?from=...&to=...
     */
    public function financialReport(Request $request)
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to'   => ['required', 'date', 'after_or_equal:from'],
        ]);

        try {
            $data = $this->computeTransportReportData($validated['from'], $validated['to']);
            return response()->json(['status' => 'success', 'data' => $data], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors de la génération du rapport.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ─── AJOUT : Rapport financier Transport — export PDF.
     * URL: GET /api/transport/reports/pdf?from=...&to=...
     */
    public function financialReportPdf(Request $request)
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to'   => ['required', 'date', 'after_or_equal:from'],
        ]);

        $data = $this->computeTransportReportData($validated['from'], $validated['to']);
        $establishment = \App\Models\Establishment::find(current_establishment_id());

        $pdf = Pdf::loadView('pdf.transport-report-pdf', [
            'data'              => $data,
            'establishmentName' => $establishment->name ?? 'Établissement scolaire',
        ]);

        return $pdf->stream('rapport-transport-' . $validated['from'] . '-au-' . $validated['to'] . '.pdf');
    }

    /**
     * ─── AJOUT : Rapport financier Transport — export Excel (4 onglets).
     * URL: GET /api/transport/reports/excel?from=...&to=...
     */
    public function financialReportExcel(Request $request)
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to'   => ['required', 'date', 'after_or_equal:from'],
        ]);

        $data = $this->computeTransportReportData($validated['from'], $validated['to']);

        return Excel::download(
            new TransportReportExport($data),
            'rapport-transport-' . $validated['from'] . '-au-' . $validated['to'] . '.xlsx'
        );
    }




}