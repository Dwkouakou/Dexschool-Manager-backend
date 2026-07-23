<?php

namespace App\Http\Controllers;


use App\Models\Personel\Employee;
use App\Models\Personel\EmployeeContrat;
use App\Models\Personel\Payroll;
use App\Models\Personel\Positions;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeController extends Controller
{
    /**
     * Liste globale de tous les employés avec poste et contrat actif.
     * URL : GET /api/employees
     */
    public function index()
    {
        try {
            // Chargement optimisé avec les relations indispensables
            // (Employee a le trait BelongsToEstablishment -> déjà scopé par établissement)
            $employees = Employee::with([
                'position',
                'contracts' => function ($query) {
                    // On cible uniquement le contrat actuellement actif
                    $query->where('status', 'active');
                }
            ])
            ->where('status', '!=', 'left') // Optionnel : masque d'office ceux qui ont quitté l'établissement
            ->latest('hire_date')
            ->get();

            // Reformatage propre des données pour simplifier la lecture côté React
            $formattedEmployees = $employees->map(function ($emp) {
                // On extrait le premier contrat actif trouvé s'il existe
                $activeContract = $emp->contracts->first();

                return [
                    'id'            => $emp->id,
                    'matricule'     => $emp->matricule,
                    'first_name'    => $emp->first_name,
                    'last_name'     => $emp->last_name,
                    'gender'        => $emp->gender,
                    'phone'         => $emp->phone,
                    'email'         => $emp->email,
                    'address'       => $emp->address,
                    'specialty'     => $emp->specialty, // Matière d'enseignement
                    'hire_date'     => $emp->hire_date ? $emp->hire_date->format('Y-m-d') : null,
                    'status'        => $emp->status,
                    'photo'         => $emp->photo,
                    'position_id'   => $emp->position_id,

                    // Envoi de l'objet de poste simplifié
                    'position'      => $emp->position ? [
                        'id'   => $emp->position->id,
                        'name' => $emp->position->name,
                    ] : null,

                    // Envoi des données financières contractuelles en Franc CFA (Entiers)
                    'active_contract' => $activeContract ? [
                        'id'            => $activeContract->id,
                        'contract_type' => $activeContract->contract_type, // CDI, CDD, vacation...
                        'base_salary'   => (int) $activeContract->base_salary, // ex: 250000
                        'start_date'    => $activeContract->start_date ? $activeContract->start_date->format('Y-m-d') : null,
                    ] : null
                ];
            });

            return response()->json($formattedEmployees, 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la récupération du personnel.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Liste des postes métiers actifs pour les sélecteurs de formulaires.
     * URL : GET /api/positions
     */
    public function getPositions()
    {
        try {
            // Positions a le trait BelongsToEstablishment -> déjà scopé par établissement
            $positions = Positions::where('is_active', 1)
                ->orderBy('name', 'asc')
                ->get(['id', 'name', 'slug']); // Optimisation : on ne prend que les colonnes utiles

            return response()->json($positions, 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Impossible de charger le catalogue des postes métiers.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Enregistrement d'un nouvel employé + initialisation de son premier contrat.
     * URL : POST /api/employees
     */
    public function store(Request $request)
    {
        // 1. Validation stricte des données d'identité et du volet financier contractuel
        $validated = $request->validate([
            'position_id'   => ['required', 'exists:positions,id'],
            'last_name'     => ['required', 'string', 'max:100'],
            'first_name'    => ['required', 'string', 'max:150'],
            'gender'        => ['required', 'in:M,F'],
            'phone'         => ['required', 'string', 'max:25'],
            'email'         => ['nullable', 'email', 'max:100', 'unique:employees,email'],
            'address'       => ['nullable', 'string', 'max:255'],
            'specialty'     => ['nullable', 'string', 'max:100'], // Matière d'enseignement si Enseignant
            'hire_date'     => ['required', 'date'],
            'photo'         => ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:2048'], // Max 2 Mo

            // Validation du contrat initial (Montants entiers pour le Franc CFA)
            'contract_type' => ['required', 'in:CDI,CDD,vacation,stage'],
            'base_salary'   => ['required', 'integer', 'min:0'],
            'start_date'    => ['required', 'date'],
            'end_date'      => ['nullable', 'date', 'after:start_date'],
        ]);

        // 2. Traitement sécurisé encapsulé dans une transaction de base de données
        return DB::transaction(function () use ($request, $validated) {

            // ─── Génération du matricule, PRÉFIXÉ PAR ÉTABLISSEMENT ───
            // Format : EMP-{PREFIXE_ETABLISSEMENT}-{ANNEE}-{COMPTEUR}
            // Ex: EMP-MFO-2026-00001 — évite toute collision entre deux écoles.
            $estabPrefix = current_establishment_prefix();
            $currentYear = date('Y');
            $matriculePrefix = "EMP-{$estabPrefix}-{$currentYear}-";

            // Employee a le trait BelongsToEstablishment -> cette requête est
            // déjà automatiquement scopée à l'établissement courant, le
            // compteur repart donc bien à 1 pour chaque nouvelle école.
            $lastEmployee = Employee::withTrashed()
                ->where('matricule', 'LIKE', $matriculePrefix . '%')
                ->latest('id')
                ->first();

            if ($lastEmployee) {
                $lastNumber = (int) substr($lastEmployee->matricule, -5);
                $nextNumber = $lastNumber + 1;
            } else {
                $nextNumber = 1;
            }

            $matricule = $matriculePrefix . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);

            // Traitement de la photo d'identité de l'employé
            $photoPath = null;
            if ($request->hasFile('photo')) {
                $file = $request->file('photo');
                $photoPath = $file->store('employees/photos', 'public');
            }

            // 3. Création du dossier permanent de l'employé
            $employee = Employee::create([
                'position_id' => $validated['position_id'],
                'matricule'   => $matricule,
                'last_name'   => strtoupper($validated['last_name']), // Forçage du nom en majuscules (Standard RH)
                'first_name'  => ucwords(strtolower($validated['first_name'])), // Première lettre en majuscule
                'gender'      => $validated['gender'],
                'phone'       => $validated['phone'],
                'email'       => $validated['email'] ?? null,
                'address'     => $validated['address'] ?? null,
                'specialty'   => $validated['specialty'] ?? null,
                'hire_date'   => $validated['hire_date'],
                'photo'       => $photoPath ? '/storage/' . $photoPath : null,
                'status'      => 'active', // Actif d'office à l'embauche
            ]);

            // 4. Création immédiate de sa fiche contractuelle financière liée (Franc CFA)
            EmployeeContrat::create([
                'employee_id'   => $employee->id,
                'contract_type' => $validated['contract_type'],
                'start_date'    => $validated['start_date'],
                // Si c'est un CDI, la date d'échéance reste à NULL d'office
                'end_date'      => $validated['contract_type'] === 'CDI' ? null : ($validated['end_date'] ?? null),
                'base_salary'   => $validated['base_salary'],
                'status'        => 'active', // Le contrat démarre actif
            ]);

            return response()->json([
                'status'   => 'success',
                'message'  => "Dossier RH créé avec succès. Matricule affecté : {$matricule}.",
                'employee' => $employee
            ], 201);
        });
    }


    /**
     * Fiche individuelle détaillée d'un employé avec ses historiques (Contrats & Paie).
     * URL : GET /api/employees/{id}
     */
    public function show(string $id)
    {
        try {
            // Chargement en cascade de tout le dossier RH de l'employé
            // (Employee scopé par le trait -> 404 automatique si autre établissement)
            $employee = Employee::with([
                'position',
                'contracts' => function ($query) {
                    $query->orderBy('start_date', 'desc'); // Historique des contrats du plus récent au plus ancien
                },
                'payrolls' => function ($query) {
                    $query->orderBy('salary_month', 'desc'); // Bulletins de paie du plus récent au plus ancien
                }
            ])->findOrFail($id);

            // Reformatage propre des blocs pour faciliter le mapping sur vos onglets React
            $formatted = [
                'id'           => $employee->id,
                'matricule'    => $employee->matricule,
                'first_name'   => $employee->first_name,
                'last_name'    => $employee->last_name,
                'gender'       => $employee->gender,
                'phone'        => $employee->phone,
                'email'        => $employee->email,
                'address'      => $employee->address,
                'photo'        => $employee->photo,
                'specialty'    => $employee->specialty, // Discipline si enseignant
                'hire_date'    => $employee->hire_date ? $employee->hire_date->format('Y-m-d') : null,
                'status'       => $employee->status, // active, suspended, left

                // Objet Poste
                'position' => $employee->position ? [
                    'id'   => $employee->position->id,
                    'name' => $employee->position->name,
                    'slug' => $employee->position->slug,
                ] : null,

                // Tableau de l'historique des contrats de travail
                'contracts' => $employee->contracts->map(function ($contract) {
                    return [
                        'id'            => $contract->id,
                        'contract_type' => $contract->contract_type, // CDI, CDD, vacation, stage
                        'start_date'    => $contract->start_date ? $contract->start_date->format('Y-m-d') : null,
                        'end_date'      => $contract->end_date ? $contract->end_date->format('Y-m-d') : 'Indéterminée (CDI)',
                        'base_salary'   => (int) $contract->base_salary, // Forçage entier FCFA
                        'status'        => $contract->status, // active, expired, terminated
                        'notes'         => $contract->notes,
                    ];
                }),

                // Tableau de l'historique de tous les bulletins de paie émis
                'payrolls' => $employee->payrolls->map(function ($payroll) {
                    return [
                        'id'             => $payroll->id,
                        'payroll_number' => $payroll->payroll_number, // PAY-XXXXXX-XXXX
                        'salary_month'   => $payroll->salary_month, // YYYY-MM
                        'base_salary'    => (int) $payroll->base_salary,
                        'allowances'     => (int) $payroll->allowances, // Primes
                        'deductions'     => (int) $payroll->deductions, // Retenues
                        'net_salary'     => (int) $payroll->net_salary, // Net perçu
                        'payment_date'   => $payroll->payment_date ? $payroll->payment_date->format('Y-m-d') : null,
                        'payment_method' => $payroll->payment_method, // cash, virement...
                        'status'         => $payroll->status, // pending, paid
                    ];
                })
            ];

            return response()->json([
                'status'   => 'success',
                'employee' => $formatted
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => "Dossier permanent de l'employé introuvable."
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la récupération de la fiche RH.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Modification des informations d'identité et du statut d'un employé.
     * URL : PUT /api/employees/{id}
     */
    public function update(Request $request, string $id)
    {
        try {
            // Employee scopé par le trait -> 404 automatique si autre établissement
            $employee = Employee::findOrFail($id);

            // 1. Validation stricte des informations modifiées
            $validated = $request->validate([
                'position_id' => ['required', 'exists:positions,id'],
                'last_name'   => ['required', 'string', 'max:100'],
                'first_name'  => ['required', 'string', 'max:150'],
                'gender'      => ['required', 'in:M,F'],
                'phone'       => ['required', 'string', 'max:25'],
                // L'email doit être unique, mais on ignore l'ID de cet employé actuel
                'email'       => ['nullable', 'email', 'max:100', Rule::unique('employees', 'email')->ignore($id)],
                'address'     => ['nullable', 'string', 'max:255'],
                'specialty'   => ['nullable', 'string', 'max:100'], // Enseignant
                'hire_date'   => ['required', 'date'],
                'status'      => ['required', 'in:active,suspended,left'], // Contrôle du statut RH
            ]);

            // 2. Formatage standardisé des noms (Standard RH de l'établissement)
            $validated['last_name']  = strtoupper($validated['last_name']);
            $validated['first_name'] = ucwords(strtolower($validated['first_name']));

            // 3. Mise à jour en base de données
            $employee->update($validated);

            return response()->json([
                'status'  => 'success',
                'message' => 'Les informations du dossier RH ont été mises à jour avec succès.'
            ], 200);

        } catch (ValidationException $e) {
            // Intercepte les erreurs de saisie (Ex: date mal formatée) et renvoie le détail à React
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur de validation des données de la fiche RH.',
                'errors'  => $e->errors()
            ], 422);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => "Dossier permanent de l'employé introuvable en base de données."
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
     * Archivage / Suppression logique d'un employé (Soft Delete).
     * URL : DELETE /api/employees/{id}
     */
    public function destroy(string $id)
    {
        try {
            // 1. Recherche du dossier de l'employé (scopé par le trait)
            $employee = Employee::findOrFail($id);

            // 2. Action de sécurité RH : On passe son statut à 'left' (A quitté l'établissement)
            // et on désactive son contrat actuel pour couper les calculs de paie automatiques
            $employee->update(['status' => 'left']);

            EmployeeContrat::where('employee_id', $employee->id)
                ->where('status', 'active')
                ->update(['status' => 'expired']);

            // 3. Déclenchement du Soft Delete de Laravel
            // Cela remplit la colonne 'deleted_at' dans la base de données
            $employee->delete();

            return response()->json([
                'status'  => 'success',
                'message' => 'Dossier employé archivé et retiré des effectifs actifs avec succès.'
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Dossier permanent de l’employé introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => "Une erreur est survenue lors de l'archivage du dossier RH.",
                'debug'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Ajout d'un nouveau contrat en cours de carrière (Évolution RH / Salaire).
     * URL : POST /api/employees/{id}/contracts
     */
    public function addContract(Request $request, string $id)
    {
        // 1. Vérifier si l'employé existe bel et bien (scopé par le trait)
        $employee = Employee::findOrFail($id);

        // 2. Validation stricte de la nouvelle fiche contractuelle (FCFA)
        $validated = $request->validate([
            'contract_type' => ['required', 'in:CDI,CDD,vacation,stage'],
            'base_salary'   => ['required', 'integer', 'min:0'],
            'start_date'    => ['required', 'date'],
            'end_date'      => ['nullable', 'date', 'after:start_date'],
            'notes'         => ['nullable', 'string']
        ]);

        // 3. Exécution sécurisée dans une transaction de base de données
        return DB::transaction(function () use ($employee, $validated) {

            // A. Passer automatiquement tous les anciens contrats de cet employé au statut 'expired'
            EmployeeContrat::where('employee_id', $employee->id)
                ->where('status', 'active')
                ->update(['status' => 'expired']);

            // B. Enregistrer la nouvelle fiche de contrat active en Franc CFA
            $contract = EmployeeContrat::create([
                'employee_id'   => $employee->id,
                'contract_type' => $validated['contract_type'],
                'start_date'    => $validated['start_date'],
                // Si c'est un CDI, la date de fin est écrasée à null
                'end_date'      => $validated['contract_type'] === 'CDI' ? null : ($validated['end_date'] ?? null),
                'base_salary'   => $validated['base_salary'],
                'status'        => 'active', // Le nouveau contrat prend directement le relais
                'notes'         => $validated['notes'] ?? null
            ]);

            return response()->json([
                'status'   => 'success',
                'message'  => 'Évolution contractuelle enregistrée. Nouveau salaire de base configuré en FCFA.',
                'contract' => $contract
            ], 201);
        });
    }


    /**
     * Rupture conventionnelle, licenciement ou fin de contrat préventive.
     * URL : PUT /api/contracts/{contract_id}/terminate
     */
    public function terminateContract(string $contract_id)
    {
        try {
            // 1. Recherche de la fiche de contrat spécifiée (scopée via employee->establishment)
            $contract = EmployeeContrat::findOrFail($contract_id);

            if ($contract->status !== 'active') {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Impossible de résilier ce contrat : il n'est pas actuellement actif."
                ], 422);
            }

            // 2. Clôture immédiate du contrat
            $contract->update([
                'status'   => 'terminated',
                'end_date' => now()->format('Y-m-d') // Fige la date de fin à aujourd'hui
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => "Contrat résilié avec succès. Le poste associé n'est plus comptabilisé financièrement."
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Fiche de contrat introuvable.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la résiliation du contrat.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Historique global et filtré de tous les bulletins de paie (Livre des Salaires).
     * URL : GET /api/payrolls
     */
    public function payrollsHistory(Request $request)
    {
        try {
            // 1. Initialisation de la requête (Payroll scopé via employee->establishment)
            $query = Payroll::with(['employee.position']);

            // 2. Filtre : Recherche par mot-clé (Nom, prénom ou matricule de l'employé)
            if ($request->filled('search')) {
                $search = $request->query('search');
                $query->whereHas('employee', function ($q) use ($search) {
                    $q->where('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%")
                    ->orWhere('matricule', 'LIKE', "%{$search}%");
                });
            }

            // 3. Filtre : Sélection par mois comptable spécifique (Format attendu : YYYY-MM)
            if ($request->filled('month')) {
                $query->where('salary_month', $request->query('month'));
            }

            // 4. Extraction par ordre de paiement le plus récent
            $payrolls = $query->latest('payment_date')->latest('id')->get();

            // 5. Reformatage propre des données pour simplifier l'intégration dans votre futur tableau React
            $formattedPayrolls = $payrolls->map(function ($payroll) {
                $emp = $payroll->employee;
                return [
                    'id'             => $payroll->id,
                    'payroll_number' => $payroll->payroll_number, // PAY-XXXXXX-XXXX
                    'salary_month'   => $payroll->salary_month,   // ex: 2026-06
                    'payment_date'   => $payroll->payment_date ? $payroll->payment_date->format('Y-m-d') : null,
                    'payment_method' => $payroll->payment_method, // cash, virement...
                    'status'         => $payroll->status,         // pending, paid

                    // Montants stricts convertis en entiers pour le Franc CFA
                    'base_salary'    => (int) $payroll->base_salary,
                    'allowances'     => (int) $payroll->allowances, // Primes
                    'deductions'     => (int) $payroll->deductions, // Retenues
                    'net_salary'     => (int) $payroll->net_salary, // Salaire net touché

                    // Inclusion des données d'identité de l'employé
                    'employee' => $emp ? [
                        'id'         => $emp->id,
                        'matricule'  => $emp->matricule,
                        'full_name'  => $emp->first_name . ' ' . $emp->last_name,
                        'position'   => $emp->position ? $emp->position->name : 'N/A',
                    ] : null
                ];
            });

            return response()->json($formattedPayrolls, 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la génération du livre des salaires.',
                'debug'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Calcule, valide et génère le bulletin de paie mensuel d'un employé.
     * URL : POST /api/payrolls
     */
    public function generatePayroll(Request $request)
    {
        // 1. Validation stricte des flux financiers entrants (FCFA entiers positifs)
        $validated = $request->validate([
            'employee_id'    => ['required', 'exists:employees,id'],
            'salary_month'   => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'], // Format strict: YYYY-MM (ex: 2026-06)
            'allowances'     => ['required', 'integer', 'min:0'], // Primes en FCFA
            'deductions'     => ['required', 'integer', 'min:0'], // Retenues en FCFA
            'payment_method' => ['required', 'in:cash,virement,cheque,mobile_money'],
            'payment_date'   => ['required', 'date']
        ]);

        // 2. Sécurité anti-doublon : Un employé ne peut recevoir qu'un seul bulletin par mois comptable
        // (Payroll scopé via employee->establishment, donc déjà isolé par école)
        $exists = Payroll::where('employee_id', $validated['employee_id'])
            ->where('salary_month', $validated['salary_month'])
            ->exists();

        if ($exists) {
            return response()->json([
                'status'  => 'error',
                'message' => "Un bulletin de paie a déjà été émis et clôturé pour cet employé concernant le mois spécifié ({$validated['salary_month']})."
            ], 422);
        }

        // 3. Récupération du contrat actif pour obtenir le salaire fixe contractuel de base
        // (Employee scopé par le trait -> 404 automatique si autre établissement)
        $employee = Employee::with(['activeContract'])->findOrFail($validated['employee_id']);
        $contract = $employee->activeContract;

        if (!$contract) {
            return response()->json([
                'status'  => 'error',
                'message' => "Opération impossible : Cet employé ne possède aucun contrat de travail actif pour le calcul du salaire de base."
            ], 422);
        }

        // 4. Calcul du Net à Percevoir en Franc CFA (Strictement entier)
        $baseSalary = (int) $contract->base_salary;
        $allowances = (int) $validated['allowances'];
        $deductions = (int) $validated['deductions'];

        // Formule comptable standard : Net = Base + Primes - Retenues
        $netSalary = ($baseSalary + $allowances) - $deductions;

        // Sécurité : Un salaire net ne peut pas être inférieur à 0 FCFA
        if ($netSalary < 0) {
            $netSalary = 0;
        }

        // 5. Exécution sécurisée dans une transaction SQL
        return DB::transaction(function () use ($validated, $contract, $baseSalary, $netSalary, $allowances, $deductions) {

            // ─── Génération du numéro de bulletin, PRÉFIXÉ PAR ÉTABLISSEMENT ───
            // Format : PAY-{PREFIXE_ETABLISSEMENT}-{ANNEEMOIS}-{COMPTEUR}
            // Ex: PAY-MFO-202606-0001 — évite toute collision entre deux écoles
            // ayant émis un bulletin le même mois comptable.
            $estabPrefix = current_establishment_prefix();
            $monthClean = str_replace('-', '', $validated['salary_month']); // Supprime le tiret (ex: 202606)
            $payrollPrefix = "PAY-{$estabPrefix}-{$monthClean}-";

            $lastPayroll = Payroll::where('payroll_number', 'LIKE', $payrollPrefix . '%')
                ->latest('id')
                ->first();

            if ($lastPayroll) {
                $lastNumber = (int) substr($lastPayroll->payroll_number, -4);
                $nextNumber = $lastNumber + 1;
            } else {
                $nextNumber = 1;
            }

            $payrollNumber = $payrollPrefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

            // Enregistrement final de l'écriture de paie
            $payroll = Payroll::create([
                'employee_id'    => $validated['employee_id'],
                'contract_id'    => $contract->id,
                'payroll_number' => $payrollNumber,
                'salary_month'   => $validated['salary_month'],
                'base_salary'    => $baseSalary,
                'allowances'     => $allowances,
                'deductions'     => $deductions,
                'net_salary'     => $netSalary,
                'payment_date'   => $validated['payment_date'],
                'payment_method' => $validated['payment_method'],
                'status'         => 'paid', // Validé et décaissé d'office
                'created_by'     => \Illuminate\Support\Facades\Auth::id() ?? null, // Traçabilité du comptable
            ]);

            return response()->json([
                'status'         => 'success',
                'message'        => "Le bulletin de paie {$payrollNumber} a été généré et archivé avec succès.",
                'payroll_number' => $payrollNumber,
                'payroll'        => $payroll
            ], 201);
        });
    }


    /**
     * Édition et impression papier du bulletin de paie (Format comptable).
     * URL : GET /api/payrolls/receipt/{id}
     */
    public function printPayrollSlip(string $id)
    {
        try {
            // 1. Récupération du bulletin avec l'employé et son poste rattaché
            // (Payroll scopé via employee->establishment)
            $payroll = Payroll::with(['employee.position'])->findOrFail($id);
            $emp = $payroll->employee;

            // Formater proprement le mois (ex: 2026-06 devient Juin 2026)
            $dateCarbon = \Illuminate\Support\Carbon::parse($payroll->salary_month . '-01');
            $moisLettres = ucfirst($dateCarbon->translatedFormat('F Y'));
            $datePaiement = \Illuminate\Support\Carbon::parse($payroll->payment_date)->format('d/m/Y');

            // Map des libellés de paiement
            $methods = [
                'cash'         => 'Espèces / Caisse',
                'virement'     => 'Virement Bancaire',
                'cheque'       => 'Chèque',
                'mobile_money' => '📱 Mobile Money (Wave/OM/MTN)'
            ];
            $moyenPaiement = $methods[$payroll->payment_method] ?? $payroll->payment_method;

            // 2. Retour du template HTML pur optimisé pour window.print()
            return "
            <!DOCTYPE html>
            <html lang='fr'>
            <head>
                <meta charset='UTF-8'>
                <title>Bulletin de Paie - {$payroll->payroll_number}</title>
                <style>
                    body { font-family: 'Courier New', Courier, monospace; font-size: 13px; color: #000; background: #fff; padding: 10px; }
                    .slip-container { width: 160mm; border: 2px solid #000; padding: 15px; margin: 0 auto; box-sizing: border-box; }
                    .header { text-align: center; margin-bottom: 15px; }
                    .header h2 { margin: 0; font-size: 20px; letter-spacing: 1px; }
                    .header p { margin: 3px 0; font-size: 11px; font-weight: bold; }
                    .flex-row { display: flex; justify-content: space-between; margin: 5px 0; }
                    .divider { border-bottom: 1px dashed #000; margin: 10px 0; }
                    .double-divider { border-bottom: 2px solid #000; margin: 10px 0; }
                    .bold { font-weight: bold; }
                    .text-right { text-align: right; }
                    .amount-box { font-size: 16px; font-weight: bold; padding: 5px; border: 1px solid #000; background: #f9f9f9; }
                    @media print {
                        body { padding: 0; }
                        .slip-container { border: 2px solid #000; }
                    }
                </style>
            </head>
            <body onload='window.print(); window.close();'>
                <div class='slip-container'>

                    <!-- En-tête de la pièce -->
                    <div class='header'>
                        <h2>BULLETIN DE PAIE</h2>
                        <p>DEXSCHOOL MANAGER — JOURNAL DE PAIE RH</p>
                    </div>

                    <div class='divider'></div>

                    <!-- Métadonnées du bulletin -->
                    <div class='flex-row'>
                        <span><span class='bold'>Pièce N° :</span> {$payroll->payroll_number}</span>
                        <span><span class='bold'>Période :</span> {$moisLettres}</span>
                    </div>
                    <div class='flex-row'>
                        <span><span class='bold'>Date d'Émission :</span> {$datePaiement}</span>
                    </div>

                    <div class='double-divider'></div>

                    <!-- Informations administratives du salarié -->
                    <p><span class='bold'>MATRICULE  :</span> {$emp->matricule}</p>
                    <p><span class='bold'>EMPLOYÉ(E) :</span> " . strtoupper($emp->last_name) . " {$emp->first_name}</p>
                    <p><span class='bold'>FONCTION   :</span> " . ($emp->position ? strtoupper($emp->position->name) : 'N/A') . "</p>
                    " . ($emp->specialty ? "<p><span class='bold'>DISCIPLINES:</span> " . strtoupper($emp->specialty) . "</p>" : "") . "

                    <div class='double-divider'></div>

                    <!-- Ventilation financière en Francs CFA -->
                    <div class='flex-row'>
                        <span>Salaire de Base Fixe Contractuel :</span>
                        <span class='bold'>" . number_format($payroll->base_salary, 0, '', ' ') . " FCFA</span>
                    </div>
                    <div class='flex-row' style='color: #27AE60;'>
                        <span>(+) Primes, Indemnités & Gratifications :</span>
                        <span>+ " . number_format($payroll->allowances, 0, '', ' ') . " FCFA</span>
                    </div>
                    <div class='flex-row' style='color: #C0395A;'>
                        <span>(-) Retenues, Avances & Absences :</span>
                        <span>- " . number_format($payroll->deductions, 0, '', ' ') . " FCFA</span>
                    </div>

                    <div class='double-divider'></div>

                    <!-- Pied de bulletin : Net à payer global -->
                    <div class='flex-row amount-box'>
                        <span>NET À PERCEVOIR :</span>
                        <span>" . number_format($payroll->net_salary, 0, '', ' ') . " FCFA</span>
                    </div>

                    <div class='divider'></div>

                    <!-- Traçabilité comptable -->
                    <p style='margin: 5px 0; font-size: 11px;'><span class='bold'>Mode de règlement :</span> {$moyenPaiement}</p>

                    <div class='flex-row' style='margin-top: 30px; font-size: 11px;'>
                        <div style='text-align: center; width: 45%;'>
                            <p class='bold'>Émargement Salarié</p>
                            <p style='margin-top: 35px; opacity: 0.2;'>[ Signature précédée de la mention lu et approuvé ]</p>
                        </div>
                        <div style='text-align: center; width: 45%;'>
                            <p class='bold'>Le Secrétariat Comptable</p>
                            <p style='margin-top: 35px; opacity: 0.2;'>[ Cachet DexSchool ]</p>
                        </div>
                    </div>

                </div>
            </body>
            </html>
            ";

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return "<html><body><p style='color:red; font-family:sans-serif; text-align:center; margin-top:50px;'>Erreur : Pièce comptable introuvable.</p></body></html>";
        } catch (\Exception $e) {
            return "<html><body><p style='color:red; font-family:sans-serif;'>Erreur technique : " . $e->getMessage() . "</p></body></html>";
        }
    }
}