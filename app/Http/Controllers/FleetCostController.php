<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\FuelLog;
use App\Models\MaintenanceRecord;
use App\Models\Vehicle;
use App\Services\FuelService;
use App\Services\MaintenanceService;
use App\Services\VehicleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FleetCostController extends Controller
{
    protected FuelService $fuelService;
    protected MaintenanceService $maintenanceService;
    protected VehicleService $vehicleService;

    public function __construct(FuelService $fuelService, MaintenanceService $maintenanceService, VehicleService $vehicleService)
    {
        $this->fuelService = $fuelService;
        $this->maintenanceService = $maintenanceService;
        $this->vehicleService = $vehicleService;
    }

    public function storeFuelLog(Request $request): RedirectResponse
    {
        if ($request->user()->role !== 'Admin') {
            // Regular users act as the driver for their own fuel log. The user_id
            // records their account; do not let the browser assign another driver.
            $request->merge(['driver_id' => null]);
        }

        $data = $request->validate([
            'vehicle_id' => ['required', 'string'],
            'driver_id' => ['nullable', 'string'],
            'liters' => ['required', 'numeric', 'min:0.1', 'max:2000'],
            'cost' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'fuel_level_before' => ['required', 'numeric', 'between:0,100'],
            'fuel_level_after' => ['required', 'numeric', 'between:0,100', 'gte:fuel_level_before'],
            'fuel_level_before_image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'fuel_level_after_image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'receipt_image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'logged_at' => ['required', 'date'],
        ]);

        $originalVehicleId = $data['vehicle_id'];
        $originalDriverId = !empty($data['driver_id']) ? $data['driver_id'] : null;

        $receiptUrl = null;
        if ($request->hasFile('receipt_image')) {
            $file = $request->file('receipt_image');
            try {
                $receiptPath = $file->store('fuel-logs/receipts', 'public');
                $receiptUrl = asset('storage/' . $receiptPath);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Fuel receipt public store skipped: ' . $e->getMessage());
            }

            if (empty($receiptUrl) && $file->isValid()) {
                try {
                    $mime = $file->getMimeType() ?: 'image/jpeg';
                    $contents = @file_get_contents($file->getRealPath());
                    if ($contents !== false) {
                        $receiptUrl = 'data:' . $mime . ';base64,' . base64_encode($contents);
                    }
                } catch (\Throwable) {}
            }
        }

        // 1. Submit to Centralized Backend Service FIRST
        $centralRes = $this->fuelService->logFuel([
            'vehicle_id' => $originalVehicleId,
            'driver_id' => $originalDriverId,
            'liters' => (float)$data['liters'],
            'cost' => (float)$data['cost'],
            'odometer' => (float)($request->input('odometer', 0)),
            'receipt_url' => $receiptUrl,
        ]);

        if (!$centralRes) {
            $errorMsg = $this->fuelService->getClient()->getLastError()
                ?? 'Failed to save fuel log to the centralized backend.';
            return back()->withInput()->withErrors(['error' => $errorMsg]);
        }

        // 2. Resolve or mirror local vehicle for SQLite foreign key integrity
        $vehicle = is_numeric($originalVehicleId)
            ? Vehicle::find($originalVehicleId)
            : Vehicle::where('plate_number', $originalVehicleId)->first();

        if (!$vehicle && !is_numeric($originalVehicleId)) {
            $centralVehicles = $this->vehicleService->getVehicles() ?? [];
            $matched = collect($centralVehicles)->first(fn($v) => ($v['id'] ?? '') === $originalVehicleId);
            $plate = $matched['plate_number'] ?? ('FLT-' . substr($originalVehicleId, 0, 6));
            $name = $matched['model'] ?? ($matched['name'] ?? 'Fleet Vehicle');

            try {
                $vehicle = Vehicle::firstOrCreate(
                    ['plate_number' => $plate],
                    [
                        'name' => $name,
                        'vehicle_code' => $plate,
                        'status' => 'Active',
                        'fuel_level' => 50,
                        'odometer' => (int) round((float)($matched['current_odometer'] ?? 0)),
                    ]
                );
            } catch (\Throwable $e) {
                // If local create fails, continue safely
            }
        }

        // 3. Record locally in SQLite (resilient, does not block on FK or schema mismatch)
        $fuelBeforeMismatch = false;
        $savedFuelLevel = 50.0;
        $localLogData = $data;

        if ($vehicle) {
            $localLogData['vehicle_id'] = $vehicle->id;
        } else {
            $fallback = Vehicle::first();
            $localLogData['vehicle_id'] = $fallback?->id ?? 1;
        }

        if (!empty($originalDriverId)) {
            $localDriver = is_numeric($originalDriverId) ? Driver::find($originalDriverId) : null;
            $localLogData['driver_id'] = $localDriver?->id;
        } else {
            $localLogData['driver_id'] = null;
        }

        try {
            DB::transaction(function () use (&$localLogData, $request, $vehicle, &$fuelBeforeMismatch, &$savedFuelLevel) {
                $savedFuelLevel = (float) ($vehicle?->fuel_level ?? 0);
                $fuelBefore = (float) ($localLogData['fuel_level_before'] ?? 0);
                $fuelBeforeMismatch = abs($fuelBefore - $savedFuelLevel) > 0.5;

                try {
                    $localLogData['fuel_level_before_image'] = $request->file('fuel_level_before_image')?->store('fuel-logs/proofs', 'local');
                } catch (\Throwable) {}

                try {
                    $localLogData['fuel_level_after_image'] = $request->file('fuel_level_after_image')?->store('fuel-logs/proofs', 'local');
                } catch (\Throwable) {}

                try {
                    $localLogData['receipt_image'] = $request->file('receipt_image')?->store('fuel-logs/proofs', 'local');
                } catch (\Throwable) {}

                $localLogData['user_id'] = $request->user()?->id ?? 1;

                FuelLog::create($localLogData);
                if ($vehicle && isset($localLogData['fuel_level_after'])) {
                    $vehicle->update(['fuel_level' => $localLogData['fuel_level_after']]);
                }
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Local SQLite fuel log mirror skipped: ' . $e->getMessage());
        }

        try {
            if ($fuelBeforeMismatch && $vehicle) {
                Alert::log('!', 'Fuel Reading Mismatch', sprintf(
                    '%s before reading was entered as %.1f; the saved vehicle reading was %.1f. Please review the proof photos.',
                    $vehicle->name ?? 'Vehicle',
                    $data['fuel_level_before'] ?? 0,
                    $savedFuelLevel,
                ), 'warning');
            }

            $driver = !empty($originalDriverId) && is_numeric($originalDriverId) ? Driver::with('user')->find($originalDriverId) : null;
            $driverName = $driver?->user?->name
                ?? $driver?->name
                ?? ($request->user()?->role !== 'Admin' ? ($request->user()?->name ?? 'Driver') : 'Assigned driver');

            Alert::log("\u{26FD}", 'Fuel Logged', sprintf(
                '%s: %.1fL logged for PHP %s by %s (driver: %s).',
                $vehicle?->name ?? 'Vehicle',
                (float)$data['liters'],
                number_format((float)$data['cost'], 2),
                $request->user()?->name ?? 'User',
                $driverName
            ));
            AuditLog::record($request, 'Fuel logged', 'Fuel & Costs', sprintf('Logged %.1fL for %s at PHP %s.', (float)$data['liters'], $vehicle?->name ?? 'vehicle', number_format((float)$data['cost'], 2)));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Alert or Audit log skipped: ' . $e->getMessage());
        }

        return back()->with('status', 'Fuel log recorded.');
    }

    public function showFuelProof(Request $request, FuelLog $fuelLog): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($user && ($user->role === 'Admin' || (int) $fuelLog->user_id === (int) $user->id), 404);
        $imagePath = match ($request->query('type', 'receipt')) {
            'before' => $fuelLog->fuel_level_before_image,
            'after' => $fuelLog->fuel_level_after_image,
            'receipt' => $fuelLog->receipt_image,
            default => null,
        };
        $disk = (!empty($imagePath) && Storage::disk('local')->exists($imagePath))
            ? 'local'
            : ((!empty($imagePath) && Storage::disk('public')->exists($imagePath)) ? 'public' : null);
        abort_unless($disk, 404);

        return response()->file(Storage::disk($disk)->path($imagePath), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function storeMaintenance(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'vehicle_id' => ['required', 'string'],
            'description' => ['required', 'string', 'max:150'],
            'cost' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'serviced_at' => ['required', 'date'],
        ]);

        $originalVehicleId = $data['vehicle_id'];

        // 1. Submit to Centralized Backend Service FIRST
        $centralRes = $this->maintenanceService->logMaintenance([
            'vehicle_id' => $originalVehicleId,
            'service_type' => 'Maintenance',
            'description' => $data['description'],
            'cost' => (float)$data['cost'],
            'serviced_at' => $data['serviced_at'],
        ]);

        if (!$centralRes) {
            $errorMsg = $this->maintenanceService->getClient()->getLastError()
                ?? 'Failed to save maintenance record to the centralized backend.';
            return back()->withInput()->withErrors(['error' => $errorMsg]);
        }

        // 2. Resolve or mirror local vehicle for SQLite
        $vehicle = is_numeric($originalVehicleId)
            ? Vehicle::find($originalVehicleId)
            : Vehicle::where('plate_number', $originalVehicleId)->first();

        if (!$vehicle && !is_numeric($originalVehicleId)) {
            $centralVehicles = $this->vehicleService->getVehicles() ?? [];
            $matched = collect($centralVehicles)->first(fn($v) => ($v['id'] ?? '') === $originalVehicleId);
            $plate = $matched['plate_number'] ?? ('FLT-' . substr($originalVehicleId, 0, 6));
            $name = $matched['model'] ?? ($matched['name'] ?? 'Fleet Vehicle');

            try {
                $vehicle = Vehicle::firstOrCreate(
                    ['plate_number' => $plate],
                    ['name' => $name, 'vehicle_code' => $plate, 'status' => 'Active', 'fuel_level' => 50, 'odometer' => 0]
                );
            } catch (\Throwable $e) {}
        }

        $localMaintData = $data;
        if ($vehicle) {
            $localMaintData['vehicle_id'] = $vehicle->id;
        } else {
            $fallback = Vehicle::first();
            $localMaintData['vehicle_id'] = $fallback?->id ?? 1;
        }

        try {
            MaintenanceRecord::create($localMaintData);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Local SQLite maintenance record mirror skipped: ' . $e->getMessage());
        }

        try {
            Alert::log("\u{1F527}", 'Maintenance Logged', sprintf(
                '%s: %s (PHP %s) logged by %s.',
                $vehicle?->name ?? 'Vehicle',
                $data['description'],
                number_format((float)$data['cost'], 2),
                $request->user()?->name ?? 'User'
            ), 'warning');
            AuditLog::record($request, 'Maintenance logged', 'Fuel & Costs', sprintf('Recorded %s maintenance for %s at PHP %s.', $data['description'], $vehicle?->name ?? 'vehicle', number_format((float)$data['cost'], 2)));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Maintenance alert or audit log skipped: ' . $e->getMessage());
        }

        return back()->with('status', 'Maintenance cost recorded.');
    }
}
