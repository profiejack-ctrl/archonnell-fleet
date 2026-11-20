<?php

namespace App\Http\Controllers;

use App\Services\VehicleService;
use App\Services\DriverService;
use App\Services\DispatchService;
use App\Services\ReservationService;
use App\Services\FuelService;
use App\Services\MaintenanceService;
use App\Services\TripExpenseService;
use App\Services\NotificationService;
use App\Services\CentralAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class PageController extends Controller
{
    protected VehicleService $vehicleService;
    protected DriverService $driverService;
    protected DispatchService $dispatchService;
    protected ReservationService $reservationService;
    protected FuelService $fuelService;
    protected MaintenanceService $maintenanceService;
    protected TripExpenseService $tripExpenseService;
    protected NotificationService $notificationService;
    protected CentralAuthService $authService;

    public function __construct(
        VehicleService $vehicleService,
        DriverService $driverService,
        DispatchService $dispatchService,
        ReservationService $reservationService,
        FuelService $fuelService,
        MaintenanceService $maintenanceService,
        TripExpenseService $tripExpenseService,
        NotificationService $notificationService,
        CentralAuthService $authService
    ) {
        $this->vehicleService = $vehicleService;
        $this->driverService = $driverService;
        $this->dispatchService = $dispatchService;
        $this->reservationService = $reservationService;
        $this->fuelService = $fuelService;
        $this->maintenanceService = $maintenanceService;
        $this->tripExpenseService = $tripExpenseService;
        $this->notificationService = $notificationService;
        $this->authService = $authService;
    }

    public function show(string $page): View
    {
        return $this->renderPage($page, '');
    }

    public function showUser(string $page): View
    {
        abort_unless(request()->user() && request()->user()->role !== 'Admin', 403, 'User access only.');

        return $this->renderPage($page, '/users');
    }

    public function activeDispatches(): JsonResponse
    {
        $user = request()->user();
        abort_unless($user, 401);

        $client = $this->dispatchService->getClient();
        if (!$client->isAvailable()) {
            return response()->json([
                'success' => false,
                'error' => 'Centralized backend is currently unavailable.',
            ], 503);
        }

        $rawDispatches = $this->dispatchService->getActiveDispatches();
        if (empty($rawDispatches)) {
            $allDispatches = $this->dispatchService->getDispatches() ?? [];
            $rawDispatches = array_values(array_filter($allDispatches, function ($d) {
                return in_array(strtolower($d['status'] ?? ''), ['active', 'in transit', 'assigned'], true);
            }));
        }

        if ($rawDispatches === null) {
            if ($client->isUnavailable()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Centralized backend is currently unavailable.',
                ], 503);
            }
            return response()->json([
                'success' => false,
                'error' => $client->getLastError() ?? 'Failed to retrieve active dispatches from centralized backend.',
            ], $client->getLastStatusCode() ?: 500);
        }

        $formatted = [];
        foreach ($rawDispatches as $d) {
            $driverName = $d['driver_name'] ?? trim(($d['driver_first_name'] ?? '') . ' ' . ($d['driver_last_name'] ?? ''));
            $formatted[] = [
                'dispatch_no' => $d['dispatch_number'] ?? $d['dispatch_no'] ?? null,
                'vehicle_id' => $d['vehicle_id'] ?? null,
                'vehicle_code' => $d['vehicle_code'] ?? $d['plate_number'] ?? null,
                'plate_number' => $d['plate_number'] ?? null,
                'driver_name' => $driverName ?: null,
                'employee_id' => $d['employee_id'] ?? $d['employee_no'] ?? null,
                'trip_record_id' => $d['id'] ?? null,
                'origin' => $d['pickup_address'] ?? $d['origin'] ?? null,
                'destination' => $d['delivery_address'] ?? $d['destination'] ?? null,
                'status' => $d['status'] ?? null,
                'trip_status' => $d['status'] ?? null,
            ];
        }

        return response()->json([
            'success' => true,
            'dispatches' => $formatted,
        ]);
    }

    private function renderPage(string $page, string $basePath): View
    {
        $titles = [
            'dashboard' => 'Dashboard Overview', 'vehicles' => 'Vehicles Management', 'reservations' => 'Reservations',
            'drivers' => 'Drivers', 'fuel-logs' => 'Fuel Logs', 'cost-analytics' => 'Cost Analytics',
            'driver-analytics' => 'Driver & Cost Analytics', 'routes' => 'Routes', 'reports' => 'Reports',
            'settings' => 'Settings', 'usermanagement' => 'User Management', 'audit-logs' => 'Audit Logs', 'notifications' => 'Notifications',
        ];

        abort_unless(isset($titles[$page]), 404);

        $user = request()->user();
        abort_if($page === 'audit-logs' && $user && $user->role !== 'Admin', 403, 'Only administrators can view audit logs.');

        $isBackendUp = $this->vehicleService->getClient()->isAvailable();
        $backendUnavailable = !$isBackendUp;
        $backendError = $backendUnavailable ? 'The centralized backend is currently unreachable. No business records can be displayed.' : null;

        $endpointErrors = [];
        $rawVehicles = [];
        $rawDrivers = [];
        $rawDispatches = [];
        $rawReservations = [];
        $rawFuelLogs = [];
        $rawMaintenance = [];
        $rawTripExpenses = [];
        $rawUsers = [];
        $rawAuditLogs = [];
        $rawAlerts = [];

        // 1. Fetch Centralized Data strictly from Centralized Backend Services
        if ($isBackendUp) {
            $rawVehicles = $this->vehicleService->getVehicles();
            if ($rawVehicles === null) {
                $endpointErrors['vehicles'] = $this->vehicleService->getClient()->getLastError() ?? 'Failed to retrieve vehicles from centralized backend.';
                $rawVehicles = [];
            }

            $rawDrivers = $this->driverService->getDrivers();
            if ($rawDrivers === null) {
                $endpointErrors['drivers'] = $this->driverService->getClient()->getLastError() ?? 'Failed to retrieve drivers from centralized backend.';
                $rawDrivers = [];
            }

            $rawDispatches = $this->dispatchService->getDispatches();
            if ($rawDispatches === null) {
                $endpointErrors['dispatches'] = $this->dispatchService->getClient()->getLastError() ?? 'Failed to retrieve dispatches from centralized backend.';
                $rawDispatches = [];
            }

            $rawReservations = $this->reservationService->getReservations();
            if ($rawReservations === null) {
                $endpointErrors['reservations'] = $this->reservationService->getClient()->getLastError() ?? 'Failed to retrieve reservations from centralized backend.';
                $rawReservations = [];
            }

            $rawFuelLogs = $this->fuelService->getFuelLogs();
            if ($rawFuelLogs === null) {
                $endpointErrors['fuel'] = $this->fuelService->getClient()->getLastError() ?? 'Failed to retrieve fuel logs from centralized backend.';
                $rawFuelLogs = [];
            }

            $rawMaintenance = $this->maintenanceService->getMaintenanceRecords();
            if ($rawMaintenance === null) {
                $endpointErrors['maintenance'] = $this->maintenanceService->getClient()->getLastError() ?? 'Failed to retrieve maintenance records from centralized backend.';
                $rawMaintenance = [];
            }

            $rawTripExpenses = $this->tripExpenseService->getTripExpenses();
            if ($rawTripExpenses === null) {
                $endpointErrors['trip_expenses'] = $this->tripExpenseService->getClient()->getLastError() ?? 'Failed to retrieve trip expenses from centralized backend.';
                $rawTripExpenses = [];
            }

            // Merge local Trip Expenses so newly submitted expenses show up immediately and add to expenses
            try {
                $localExpenses = \App\Models\TripExpense::with(['vehicle', 'user'])->orderByDesc('id')->get();
                foreach ($localExpenses as $le) {
                    $dateStr = $le->expense_date ? Carbon::parse($le->expense_date)->toDateString() : ($le->created_at ? Carbon::parse($le->created_at)->toDateString() : date('Y-m-d'));
                    $rawTripExpenses[] = [
                        'id' => $le->id,
                        'category' => $le->category,
                        'description' => $le->description,
                        'amount' => (float)$le->amount,
                        'expense_date' => $dateStr,
                        'created_at' => $le->created_at ? $le->created_at->toISOString() : Carbon::parse($dateStr)->toISOString(),
                        'plate_number' => $le->vehicle?->plate_number ?? ($le->vehicle?->vehicle_code ?? 'Fleet Vehicle'),
                        'vehicle_name' => $le->vehicle?->name ?? 'Fleet Vehicle',
                        'submitted_by' => $le->user?->name ?? 'Staff',
                        'receipt_url' => $le->receipt_path ? asset('storage/' . $le->receipt_path) : null,
                        'receipt_path' => $le->receipt_path,
                    ];
                }
            } catch (\Throwable) {}

            // Merge local Maintenance records so newly logged maintenance shows up immediately and adds to costs
            try {
                $localMaintenance = \App\Models\MaintenanceRecord::with('vehicle')->orderByDesc('id')->get();
                foreach ($localMaintenance as $lm) {
                    $servicedDate = $lm->serviced_at ? Carbon::parse($lm->serviced_at)->toDateString() : ($lm->created_at ? Carbon::parse($lm->created_at)->toDateString() : date('Y-m-d'));
                    $rawMaintenance[] = [
                        'id' => $lm->id,
                        'vehicle_id' => $lm->vehicle_id,
                        'plate_number' => $lm->vehicle?->plate_number ?? ($lm->vehicle?->vehicle_code ?? 'Fleet Vehicle'),
                        'vehicle_model' => $lm->vehicle?->name ?? 'Vehicle',
                        'service_type' => 'Maintenance',
                        'description' => $lm->description,
                        'cost' => (float)$lm->cost,
                        'serviced_at' => $servicedDate,
                        'created_at' => $lm->created_at ? $lm->created_at->toISOString() : Carbon::parse($servicedDate)->toISOString(),
                        'status' => 'Completed',
                    ];
                }
            } catch (\Throwable) {}

            // Merge local Fuel logs so any local-only fuel logs also appear
            try {
                $localFuel = \App\Models\FuelLog::with(['vehicle', 'driver', 'user'])->orderByDesc('id')->get();
                foreach ($localFuel as $lf) {
                    $logDate = $lf->logged_at ? Carbon::parse($lf->logged_at)->toDateString() : ($lf->created_at ? Carbon::parse($lf->created_at)->toDateString() : date('Y-m-d'));
                    $already = collect($rawFuelLogs)->contains(fn($rf) => abs((float)($rf['liters'] ?? 0) - (float)$lf->liters) < 0.01 && abs((float)($rf['cost'] ?? 0) - (float)$lf->cost) < 0.01);
                    if (!$already) {
                        $rawFuelLogs[] = [
                            'id' => $lf->id,
                            'vehicle_id' => $lf->vehicle_id,
                            'driver_id' => $lf->driver_id,
                            'liters' => (float)$lf->liters,
                            'cost' => (float)$lf->cost,
                            'logged_at' => $logDate,
                            'created_at' => $lf->created_at ? $lf->created_at->toISOString() : Carbon::parse($logDate)->toISOString(),
                            'plate_number' => $lf->vehicle?->plate_number ?? 'Fleet Vehicle',
                            'vehicle_model' => $lf->vehicle?->name ?? 'Vehicle',
                            'driver_first_name' => $lf->driver?->driver_name ?? ($lf->user?->name ?? 'Driver'),
                            'driver_last_name' => '',
                        ];
                    }
                }
            } catch (\Throwable) {}

            if ($page === 'usermanagement') {
                $rawUsers = $this->authService->getUsers();
                if ($rawUsers === null) {
                    $rawUsers = \App\Models\User::query()->orderBy('name')->get()->map(fn($u) => [
                        'id' => $u->id,
                        'name' => $u->name,
                        'email' => $u->email,
                        'role' => $u->role,
                        'status' => $u->status ?? 'active',
                    ])->toArray();
                }
            }

            if ($page === 'audit-logs') {
                $rawAuditLogs = $this->authService->getAuditLogs(request()->query('module'), request()->query('search'));
                if ($rawAuditLogs === null) {
                    $query = \App\Models\AuditLog::query()->with('user')->latest();
                    if ($mod = request()->query('module')) {
                        $query->where('module', $mod);
                    }
                    if ($srch = request()->query('search')) {
                        $query->where(function($q) use ($srch) {
                            $q->where('action', 'like', "%{$srch}%")
                              ->orWhere('description', 'like', "%{$srch}%");
                        });
                    }
                    $rawAuditLogs = $query->take(100)->get()->map(fn($a) => [
                        'id' => $a->id,
                        'actor_name' => $a->user?->name ?? 'System',
                        'user_id' => $a->user_id,
                        'action' => $a->action,
                        'module' => $a->module,
                        'details' => $a->description,
                        'ip_address' => $a->ip_address,
                        'created_at' => $a->created_at?->toIso8601String(),
                    ])->toArray();
                }
            }

            $rawAlerts = $this->notificationService->getNotifications();
            if ($rawAlerts === null) {
                $endpointErrors['alerts'] = $this->notificationService->getClient()->getLastError() ?? 'Failed to retrieve notifications from centralized backend.';
                $rawAlerts = [];
            }
        }

        // 2. Map Vehicles
        $vehicleObjects = [];
        foreach ($rawVehicles as $v) {
            $code = $v['vehicle_code'] ?? ($v['plate_number'] ?? null);

            // Group maintenance issues for this vehicle
            $vhcMaint = array_filter($rawMaintenance, fn($m) => ($m['vehicle_id'] ?? '') == ($v['id'] ?? ''));
            $maintIssuesCol = collect($vhcMaint)->map(fn($m) => (object)[
                'id' => $m['id'],
                'details' => $m['description'] ?? ($m['service_type'] ?? null),
                'status' => $m['status'] ?? null,
                'photo_path' => null,
                'reporter' => (object)['name' => $m['serviced_by'] ?? ($m['reporter_name'] ?? null)],
                'created_at' => isset($m['serviced_at']) ? Carbon::parse($m['serviced_at']) : null,
            ]);

            $vehicleObjects[] = (object)[
                'id' => $v['id'],
                'vehicle_code' => $code,
                'plate_number' => $v['plate_number'] ?? null,
                'name' => $v['model'] ?? ($v['name'] ?? null),
                'type' => $v['vehicle_type'] ?? ($v['type'] ?? null),
                'status' => $v['status'] ?? null,
                'fuel_level' => isset($v['fuel_level']) ? (float)$v['fuel_level'] : (isset($v['fuel_capacity_liters']) ? (float)$v['fuel_capacity_liters'] : 0.0),
                'odometer' => (int) round((float)($v['current_odometer'] ?? ($v['odometer'] ?? 0))),
                'maintenanceIssues' => $maintIssuesCol,
            ];
        }

        // 3. Map Fuel Logs
        $fuelLogs = [];
        if ($page === 'fuel-logs') {
            foreach ($rawFuelLogs as $log) {
                $driverName = trim(($log['driver_first_name'] ?? '') . ' ' . ($log['driver_last_name'] ?? ''));
                $fuelLogs[] = [
                    'vehicle' => $log['vehicle_model'] ?? ($log['vehicle_code'] ?? ($log['plate_number'] ?? null)),
                    'plate_number' => $log['plate_number'] ?? null,
                    'driver' => $driverName ?: ($log['submitted_by'] ?? 'Assigned Driver'),
                    'submitted_by' => $log['submitted_by'] ?? ($driverName ?: 'System Operator'),
                    'fuel_level_before' => isset($log['fuel_level_before']) ? (float)$log['fuel_level_before'] : null,
                    'fuel_level_after' => isset($log['fuel_level_after']) ? (float)$log['fuel_level_after'] : null,
                    'fuel_level_before_image' => null,
                    'fuel_level_after_image' => null,
                    'receipt_image' => $log['receipt_url'] ?? null,
                    'id' => $log['id'] ?? null,
                    'logged_at' => isset($log['created_at']) ? Carbon::parse($log['created_at'])->format('M d, Y') : null,
                    'liters' => isset($log['liters']) ? (number_format((float)$log['liters'], 1) . 'L') : null,
                    'cost' => isset($log['cost']) ? number_format((float)$log['cost'], 2) : null,
                ];
            }
        }

        // 4. Map Trip Expenses
        $tripExpenses = [];
        if ($page === 'cost-analytics') {
            foreach ($rawTripExpenses as $expense) {
                $tripExpenses[] = [
                    'id' => $expense['id'] ?? null,
                    'category' => $expense['category'] ?? null,
                    'description' => $expense['description'] ?? '',
                    'amount' => (float)($expense['amount'] ?? 0),
                    'date' => isset($expense['expense_date']) ? Carbon::parse($expense['expense_date'])->format('M d, Y') : null,
                    'vehicle' => $expense['plate_number'] ?? ($expense['vehicle_name'] ?? null),
                    'submitted_by' => $expense['submitted_by'] ?? null,
                    'receipt_url' => $expense['receipt_url'] ?? null,
                    'receipt_path' => $expense['receipt_path'] ?? null,
                ];
            }
        }

        // 5. Build Financial Data from Centralized Logs
        $finance = $this->buildFinanceDataFromCentral($rawFuelLogs, $rawMaintenance, $rawTripExpenses);

        // 6. Counts & Statuses
        $vehicleCount = count($rawVehicles);
        $activeVehicleCount = count(array_filter($rawVehicles, fn($v) => in_array(strtolower($v['status'] ?? ''), ['active', 'available'], true)));
        $maintenanceVehicleCount = count(array_filter($rawVehicles, fn($v) => strtolower($v['status'] ?? '') === 'maintenance'));
        $pendingReservationCount = count(array_filter($rawReservations, fn($r) => strtolower($r['status'] ?? '') === 'pending'));

        // 7. Map Drivers
        $driversList = [];
        foreach ($rawDrivers as $d) {
            $name = trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));
            $driverTripCount = count(array_filter($rawDispatches, fn($dsp) => ($dsp['driver_id'] ?? null) == $d['id']));
            $score = isset($d['rating']) ? number_format((float)$d['rating'], 1) : (isset($d['score']) ? number_format((float)$d['score'], 1) : '5.0');
            $driversList[] = [
                'id' => $d['id'],
                'name' => $name,
                'role' => $d['department_name'] ?? ($d['role'] ?? 'Fleet Driver'),
                'dispatches' => "{$driverTripCount} dispatches",
                'score' => $score,
                'employee_id' => $d['employee_no'] ?? '',
                'status' => isset($d['status']) ? $d['status'] : (!empty($d['is_available']) ? 'Active' : 'Busy'),
            ];
        }

        // 8. Vehicle Availability Breakdown
        $availabilityCounts = ['Available' => 0, 'Booked' => 0, 'Maintenance' => 0, 'Delayed' => 0, 'Unavailable' => 0];
        foreach ($rawVehicles as $v) {
            $cat = match (strtolower(trim($v['status'] ?? ''))) {
                'active', 'available' => 'Available',
                'reserved', 'booked', 'in transit', 'on trip' => 'Booked',
                'maintenance' => 'Maintenance',
                'delayed' => 'Delayed',
                default => 'Unavailable',
            };
            $availabilityCounts[$cat]++;
        }
        $vehicleAvailability = [
            'counts' => $availabilityCounts,
            'total' => array_sum($availabilityCounts),
        ];

        // 9. Dashboard Alerts
        $alertsList = [];
        foreach ($rawAlerts as $a) {
            $alertsList[] = [
                'id' => $a['id'] ?? '',
                'icon' => $a['icon'] ?? 'ℹ️',
                'title' => $a['title'] ?? ($a['type'] ?? ''),
                'detail' => $a['detail'] ?? ($a['message'] ?? ''),
                'severity' => $a['severity'] ?? 'info',
                'read' => !empty($a['read_at']),
                'time' => isset($a['created_at']) ? Carbon::parse($a['created_at'])->diffForHumans() : '',
            ];
        }

        // 10. Dashboard Reservations (top 5)
        $reservationsTop = [];
        foreach (array_slice($rawReservations, 0, 5) as $res) {
            $name = $res['driver_name'] ?? ($res['employee_id'] ?? ($res['destination'] ?? ($res['delivery_address'] ?? null)));
            $vehicleType = $res['vehicle_type'] ?? ($res['vehicle_model'] ?? ($res['plate_number'] ?? 'Standard Fleet'));
            $reservationsTop[] = [
                'name' => $name ?: 'Fleet Request',
                'vehicle' => $vehicleType ?: 'Standard Fleet',
                'date' => $res['requested_date'] ?? (isset($res['scheduled_at']) ? substr($res['scheduled_at'], 0, 10) : (isset($res['created_at']) ? substr($res['created_at'], 0, 10) : '—')),
                'duration' => isset($res['duration_days']) ? (($res['duration_days'] ?? 1) . ' day' . (($res['duration_days'] ?? 1) === 1 ? '' : 's')) : '1 day',
                'status' => isset($res['status']) ? ucfirst($res['status']) : 'Scheduled',
            ];
        }

        // 11. Vehicle and Driver Options for dropdowns
        $vehicleOptions = array_map(fn($v) => (object)[
            'id' => $v->id,
            'name' => $v->name ?? $v->plate_number,
            'type' => $v->type,
            'plate_number' => $v->plate_number,
            'fuel_level' => $v->fuel_level,
        ], $vehicleObjects);

        $driverOptions = array_map(fn($d) => (object)[
            'id' => $d['id'],
            'employee_id' => $d['employee_id'],
            'display_name' => $d['name'],
        ], $driversList);

        $dashboard = [
            'page' => $page,
            'isAdmin' => $user ? in_array(strtolower($user->role), ['admin', 'fleet_manager', 'superadmin'], true) : true,
            'title' => $titles[$page],
            'basePath' => $basePath,
            'user' => [
                'id' => $user?->id,
                'name' => $user?->name ?? null,
                'email' => $user?->email ?? null,
                'title' => $user?->role ?? null,
                'role' => $user?->role ?? null,
                'initials' => $user?->name ? strtoupper(substr($user->name, 0, 2)) : '',
                'profilePhotoUrl' => $user?->profile_photo_path ? route('profile-photo', ['v' => $user->updated_at?->timestamp ?? $user->id]) : null,
                'preferences' => $user?->preferences_with_defaults ?? [],
            ],
            'stats' => [
                ['title' => 'Total Vehicles', 'value' => $vehicleCount, 'meta' => 'Centralized DB total', 'positive' => true, 'currency' => false],
                ['title' => 'Active Vehicles', 'value' => $activeVehicleCount, 'meta' => 'Currently active', 'positive' => true, 'currency' => false],
                ['title' => 'Vehicles in Maintenance', 'value' => $maintenanceVehicleCount, 'meta' => 'Requires attention', 'positive' => false, 'currency' => false],
                ['title' => 'Available Dispatches', 'value' => $pendingReservationCount, 'meta' => 'Pending reservations', 'positive' => true, 'currency' => false],
                ['title' => 'Transport Costs This Month', 'value' => $finance['totals']['total_this_month'], 'meta' => 'Fuel + maintenance, live', 'positive' => true, 'currency' => true, 'currency_symbol' => 'PHP '],
            ],
            'vehicleAvailability' => $vehicleAvailability,
            'reservations' => $reservationsTop,
            'alerts' => array_slice($alertsList, 0, 4),
            'drivers' => $driversList,
            'users' => $rawUsers,
            'auditLogs' => array_map(fn($log) => [
                'actor' => $log['actor_name'] ?? null,
                'action' => $log['action'] ?? null,
                'module' => $log['module'] ?? null,
                'description' => is_string($log['details'] ?? null) ? $log['details'] : (isset($log['details']) ? json_encode($log['details']) : null),
                'ip' => $log['ip_address'] ?? null,
                'created_at' => isset($log['created_at']) ? Carbon::parse($log['created_at'])->format('M d, Y · h:i A') : null,
                'relative_time' => isset($log['created_at']) ? Carbon::parse($log['created_at'])->diffForHumans() : null,
            ], $rawAuditLogs),
            'auditStats' => [
                'total' => count($rawAuditLogs),
                'today' => count(array_filter($rawAuditLogs, fn($l) => isset($l['created_at']) && Carbon::parse($l['created_at'])->isToday())),
                'actors' => count(array_unique(array_filter(array_column($rawAuditLogs, 'user_id')))),
            ],
            'auditModules' => array_values(array_unique(array_filter(array_column($rawAuditLogs, 'module')))),
            'userRoles' => ['User', 'Driver', 'Admin'],
            'vehicleOptions' => $vehicleOptions,
            'driverOptions' => $driverOptions,
            'fuelLogs' => $fuelLogs,
            'tripExpenses' => $tripExpenses,
            'quickActions' => ($user?->role === 'Admin')
                ? ['Add Vehicle', 'Log Fuel', 'Create Reservation', 'Report Incident', 'Dispatch Log', 'View Routes', 'Check Drivers', 'Settings']
                : ['View Vehicles', 'Review Fuel Logs', 'View Reservations', 'View Routes', 'Review Drivers', 'View Reports', 'View Notifications', 'Settings'],
            'finance' => $finance,
            'unreadNotifications' => count(array_filter($alertsList, fn($a) => !$a['read'])),
            'notifications' => $alertsList,
            'vehicles' => $vehicleObjects,
            'nextVehicleCode' => null,
            'backendUnavailable' => $backendUnavailable,
            'backendError' => $backendError,
            'endpointErrors' => $endpointErrors,
        ];

        // Page-specific structures
        if ($page === 'reservations') {
            $dashboard['pendingReservations'] = array_map(fn($r) => (object)[
                'id' => $r['id'] ?? null,
                'reservation_no' => !empty($r['reservation_no']) ? $r['reservation_no'] : (!empty($r['dispatch_number']) ? $r['dispatch_number'] : (!empty($r['dispatch_no']) ? $r['dispatch_no'] : 'RES-'.substr($r['id'] ?? '0000', 0, 8))),
                'employee_id' => !empty($r['employee_id']) ? $r['employee_id'] : (!empty($r['requester_name']) ? $r['requester_name'] : (!empty($r['driver_name']) ? $r['driver_name'] : 'Fleet Requester')),
                'destination' => !empty($r['destination']) ? $r['destination'] : (!empty($r['delivery_address']) ? $r['delivery_address'] : 'Site Delivery'),
                'requested_date' => $r['requested_date'] ?? (isset($r['scheduled_at']) ? substr($r['scheduled_at'], 0, 10) : null),
                'vehicle_type' => !empty($r['vehicle_type']) ? $r['vehicle_type'] : 'Truck',
                'passenger_count' => $r['passenger_count'] ?? 1,
                'status' => 'Pending',
            ], array_filter($rawReservations, fn($r) => in_array(strtolower($r['status'] ?? ''), ['pending', 'scheduled'], true)));

            $dashboard['approvedReservations'] = array_map(fn($r) => (object)[
                'id' => $r['id'] ?? null,
                'reservation_no' => !empty($r['reservation_no']) ? $r['reservation_no'] : (!empty($r['dispatch_number']) ? $r['dispatch_number'] : (!empty($r['dispatch_no']) ? $r['dispatch_no'] : 'RES-'.substr($r['id'] ?? '0000', 0, 8))),
                'employee_id' => !empty($r['employee_id']) ? $r['employee_id'] : (!empty($r['requester_name']) ? $r['requester_name'] : (!empty($r['driver_name']) ? $r['driver_name'] : 'Fleet Requester')),
                'destination' => !empty($r['destination']) ? $r['destination'] : (!empty($r['delivery_address']) ? $r['delivery_address'] : 'Site Delivery'),
                'requested_date' => $r['requested_date'] ?? (isset($r['scheduled_at']) ? substr($r['scheduled_at'], 0, 10) : null),
                'vehicle_type' => !empty($r['vehicle_type']) ? $r['vehicle_type'] : 'Truck',
                'passenger_count' => $r['passenger_count'] ?? 1,
                'status' => 'Approved',
            ], array_filter($rawReservations, fn($r) => in_array(strtolower($r['status'] ?? ''), ['approved', 'assigned'], true)));

            $dashboard['rejectedReservations'] = array_map(fn($r) => (object)[
                'id' => $r['id'] ?? null,
                'reservation_no' => !empty($r['reservation_no']) ? $r['reservation_no'] : (!empty($r['dispatch_number']) ? $r['dispatch_number'] : (!empty($r['dispatch_no']) ? $r['dispatch_no'] : 'RES-'.substr($r['id'] ?? '0000', 0, 8))),
                'employee_id' => !empty($r['employee_id']) ? $r['employee_id'] : (!empty($r['requester_name']) ? $r['requester_name'] : (!empty($r['driver_name']) ? $r['driver_name'] : 'Fleet Requester')),
                'destination' => !empty($r['destination']) ? $r['destination'] : (!empty($r['delivery_address']) ? $r['delivery_address'] : 'Site Delivery'),
                'requested_date' => $r['requested_date'] ?? (isset($r['scheduled_at']) ? substr($r['scheduled_at'], 0, 10) : (isset($r['created_at']) ? substr($r['created_at'], 0, 10) : null)),
                'status' => 'Rejected',
            ], array_filter($rawReservations, fn($r) => strtolower($r['status'] ?? '') === 'rejected'));

            $dashboard['dispatchedReservations'] = array_map(fn($r) => (object)[
                'id' => $r['id'] ?? null,
                'reservation_no' => !empty($r['reservation_no']) ? $r['reservation_no'] : (!empty($r['dispatch_number']) ? $r['dispatch_number'] : (!empty($r['dispatch_no']) ? $r['dispatch_no'] : 'RES-'.substr($r['id'] ?? '0000', 0, 8))),
                'employee_id' => !empty($r['employee_id']) ? $r['employee_id'] : (!empty($r['requester_name']) ? $r['requester_name'] : (!empty($r['driver_name']) ? $r['driver_name'] : 'Fleet Requester')),
                'destination' => !empty($r['destination']) ? $r['destination'] : (!empty($r['delivery_address']) ? $r['delivery_address'] : 'Site Delivery'),
                'requested_date' => $r['requested_date'] ?? (isset($r['scheduled_at']) ? substr($r['scheduled_at'], 0, 10) : (isset($r['created_at']) ? substr($r['created_at'], 0, 10) : null)),
                'status' => 'Dispatched',
            ], array_filter($rawReservations, fn($r) => strtolower($r['status'] ?? '') === 'dispatched'));

            $dashboard['scheduledDispatches'] = array_map(fn($d) => (object)[
                'id' => $d['id'] ?? null,
                'dispatch_no' => !empty($d['dispatch_number']) ? $d['dispatch_number'] : (!empty($d['dispatch_no']) ? $d['dispatch_no'] : 'DSP-'.substr($d['id'] ?? '0000', 0, 8)),
                'plate_number' => !empty($d['plate_number']) ? $d['plate_number'] : (!empty($d['vehicle_code']) ? $d['vehicle_code'] : 'Unassigned'),
                'driver_name' => !empty($d['driver_name']) ? $d['driver_name'] : (trim(($d['driver_first_name'] ?? '') . ' ' . ($d['driver_last_name'] ?? '')) ?: 'Unassigned'),
                'destination' => !empty($d['destination']) ? $d['destination'] : (!empty($d['delivery_address']) ? $d['delivery_address'] : 'Site Delivery'),
                'priority' => !empty($d['priority']) ? $d['priority'] : 'Normal',
                'status' => 'Scheduled',
            ], array_filter($rawDispatches, fn($d) => strtolower($d['status'] ?? '') === 'scheduled'));

            $dashboard['activeDispatches'] = array_map(fn($d) => (object)[
                'id' => $d['id'] ?? null,
                'dispatch_no' => !empty($d['dispatch_number']) ? $d['dispatch_number'] : (!empty($d['dispatch_no']) ? $d['dispatch_no'] : 'DSP-'.substr($d['id'] ?? '0000', 0, 8)),
                'plate_number' => !empty($d['plate_number']) ? $d['plate_number'] : (!empty($d['vehicle_code']) ? $d['vehicle_code'] : 'Standard Fleet'),
                'driver_name' => !empty($d['driver_name']) ? $d['driver_name'] : (trim(($d['driver_first_name'] ?? '') . ' ' . ($d['driver_last_name'] ?? '')) ?: 'Designated Driver'),
                'destination' => !empty($d['destination']) ? $d['destination'] : (!empty($d['delivery_address']) ? $d['delivery_address'] : 'Site Delivery'),
                'status' => 'Active',
                'created_at' => $d['created_at'] ?? null,
                'updated_at' => $d['updated_at'] ?? $d['completed_at'] ?? $d['created_at'] ?? null,
            ], array_filter($rawDispatches, fn($d) => in_array(strtolower($d['status'] ?? ''), ['active', 'in transit', 'assigned'], true)));

            $dashboard['availableVehicles'] = array_map(fn($v) => (object)$v, array_filter($rawVehicles, fn($v) => in_array(strtolower($v['status'] ?? ''), ['active', 'available'], true)));
            $dashboard['availableDrivers'] = array_map(fn($d) => (object)[
                'id' => $d['id'],
                'employee_id' => $d['employee_no'] ?? '',
                'driver_name' => trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? '')),
            ], array_filter($rawDrivers, fn($d) => !empty($d['is_available'])));
        }

        if ($page === 'driver-analytics') {
            $driverData = [];
            foreach ($rawDrivers as $i => $d) {
                $name = trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));
                $driverTrips = count(array_filter($rawDispatches, fn($dsp) => ($dsp['driver_id'] ?? null) == $d['id']));
                $driverData[] = [
                    'id' => $d['id'],
                    'name' => $name,
                    'employee_id' => $d['employee_no'] ?? '',
                    'role' => $d['department_name'] ?? ($d['role'] ?? ''),
                    'status' => isset($d['status']) ? $d['status'] : (!empty($d['is_available']) ? 'Active' : 'Busy'),
                    'score' => isset($d['rating']) ? (float)$d['rating'] : (isset($d['score']) ? (float)$d['score'] : null),
                    'on_time' => isset($d['on_time']) ? (float)$d['on_time'] : null,
                    'fuel_eff' => isset($d['fuel_eff']) ? (float)$d['fuel_eff'] : null,
                    'safety' => isset($d['safety']) ? (float)$d['safety'] : null,
                    'attendance' => isset($d['attendance']) ? (float)$d['attendance'] : null,
                    'total_trips' => $driverTrips,
                    'completed' => count(array_filter($rawDispatches, fn($dsp) => ($dsp['driver_id'] ?? null) == $d['id'] && in_array(strtolower($dsp['status'] ?? ''), ['completed', 'delivered'], true))),
                    'delayed' => count(array_filter($rawDispatches, fn($dsp) => ($dsp['driver_id'] ?? null) == $d['id'] && in_array(strtolower($dsp['status'] ?? ''), ['delayed', 'critical delay'], true))),
                    'km_per_liter' => isset($d['km_per_liter']) ? (float)$d['km_per_liter'] : null,
                    'risk' => $d['risk_rating'] ?? ($d['risk'] ?? null),
                    'rank' => $i + 1,
                ];
            }
            $dashboard['driverData'] = $driverData;
            $dashboard['tripRecords'] = [];
        }

        return view('layout', compact('dashboard'));
    }

    private function buildFinanceDataFromCentral(array $fuelLogs, array $maintenance, array $tripExpenses): array
    {
        $now = Carbon::now();
        $startOfThisMonth = $now->copy()->startOfMonth();
        $endOfThisMonth = $startOfThisMonth->copy()->endOfMonth();
        $startOfLastMonth = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $endOfLastMonth = $startOfThisMonth->copy()->subSecond();

        $fuelThisMonth = 0.0;
        $fuelLastMonth = 0.0;
        foreach ($fuelLogs as $f) {
            $date = isset($f['logged_at']) ? Carbon::parse($f['logged_at']) : (isset($f['created_at']) ? Carbon::parse($f['created_at']) : null);
            if ($date && $date->between($startOfThisMonth, $endOfThisMonth)) {
                $fuelThisMonth += (float)($f['cost'] ?? 0);
            } elseif ($date && $date->between($startOfLastMonth, $endOfLastMonth)) {
                $fuelLastMonth += (float)($f['cost'] ?? 0);
            }
        }

        $maintThisMonth = 0.0;
        $maintLastMonth = 0.0;
        foreach ($maintenance as $m) {
            $date = isset($m['serviced_at']) ? Carbon::parse($m['serviced_at']) : (isset($m['created_at']) ? Carbon::parse($m['created_at']) : null);
            if ($date && $date->between($startOfThisMonth, $endOfThisMonth)) {
                $maintThisMonth += (float)($m['cost'] ?? 0);
            } elseif ($date && $date->between($startOfLastMonth, $endOfLastMonth)) {
                $maintLastMonth += (float)($m['cost'] ?? 0);
            }
        }

        $expenseTotals = [];
        $tripExpensesThisMonth = 0.0;
        $tripExpensesLastMonth = 0.0;
        foreach ($tripExpenses as $e) {
            $date = isset($e['expense_date']) ? Carbon::parse($e['expense_date']) : (isset($e['created_at']) ? Carbon::parse($e['created_at']) : null);
            $cat = $e['category'] ?? null;
            if ($cat !== null && !isset($expenseTotals[$cat])) {
                $expenseTotals[$cat] = 0.0;
            }

            if ($date && $date->between($startOfThisMonth, $endOfThisMonth)) {
                if ($cat !== null) {
                    $expenseTotals[$cat] += (float)($e['amount'] ?? 0);
                }
                $tripExpensesThisMonth += (float)($e['amount'] ?? 0);
            } elseif ($date && $date->between($startOfLastMonth, $endOfLastMonth)) {
                $tripExpensesLastMonth += (float)($e['amount'] ?? 0);
            }
        }

        $totalThisMonth = $fuelThisMonth + $maintThisMonth + $tripExpensesThisMonth;
        $totalLastMonth = $fuelLastMonth + $maintLastMonth + $tripExpensesLastMonth;

        $pctChange = function (float $current, float $previous): ?float {
            if ($previous <= 0.0) return null;
            return round((($current - $previous) / $previous) * 100, 1);
        };

        $trendLabels = [];
        $trendValues = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthStart = $now->copy()->subMonthsNoOverflow($i)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();

            $monthFuel = 0.0;
            foreach ($fuelLogs as $f) {
                $date = isset($f['created_at']) ? Carbon::parse($f['created_at']) : null;
                if ($date && $date->between($monthStart, $monthEnd)) {
                    $monthFuel += (float)($f['cost'] ?? 0);
                }
            }

            $monthMaint = 0.0;
            foreach ($maintenance as $m) {
                $date = isset($m['serviced_at']) ? Carbon::parse($m['serviced_at']) : null;
                if ($date && $date->between($monthStart, $monthEnd)) {
                    $monthMaint += (float)($m['cost'] ?? 0);
                }
            }

            $monthExpenses = 0.0;
            foreach ($tripExpenses as $e) {
                $date = isset($e['expense_date']) ? Carbon::parse($e['expense_date']) : null;
                if ($date && $date->between($monthStart, $monthEnd)) {
                    $monthExpenses += (float)($e['amount'] ?? 0);
                }
            }

            $trendLabels[] = $monthStart->format('M');
            $trendValues[] = round($monthFuel + $monthMaint + $monthExpenses, 2);
        }

        $breakdownLabels = ['Fuel', 'Maintenance'];
        $breakdownValues = [round($fuelThisMonth, 2), round($maintThisMonth, 2)];
        $breakdownColors = ['#ef4444', '#f59e0b'];
        $palette = ['#8b5cf6', '#06b6d4', '#10b981', '#f97316', '#64748b'];
        $pIdx = 0;
        foreach ($expenseTotals as $catName => $catVal) {
            $breakdownLabels[] = $catName;
            $breakdownValues[] = round($catVal, 2);
            $breakdownColors[] = $palette[$pIdx % count($palette)];
            $pIdx++;
        }

        return [
            'cards' => [
                ['key' => 'total_transport_cost', 'title' => 'Total Transport Cost', 'value' => $totalThisMonth, 'change' => $pctChange($totalThisMonth, $totalLastMonth), 'meta' => 'vs last month'],
                ['key' => 'fuel_expenses', 'title' => 'Fuel Expenses', 'value' => $fuelThisMonth, 'change' => $pctChange($fuelThisMonth, $fuelLastMonth), 'meta' => 'vs last month'],
                ['key' => 'maintenance_costs', 'title' => 'Maintenance Costs', 'value' => $maintThisMonth, 'change' => $pctChange($maintThisMonth, $maintLastMonth), 'meta' => 'vs last month'],
                ['key' => 'trip_expenses', 'title' => 'Trip Expenses', 'value' => $tripExpensesThisMonth, 'meta' => 'Active monthly total'],
            ],
            'trend' => [
                'labels' => $trendLabels,
                'values' => $trendValues,
            ],
            'breakdown' => [
                'labels' => $breakdownLabels,
                'values' => $breakdownValues,
                'colors' => $breakdownColors,
            ],
            'totals' => array_merge([
                'fuel_this_month' => $fuelThisMonth,
                'maintenance_this_month' => $maintThisMonth,
                'trip_expenses_this_month' => $tripExpensesThisMonth,
                'total_this_month' => $totalThisMonth,
            ], $expenseTotals),
        ];
    }
}
