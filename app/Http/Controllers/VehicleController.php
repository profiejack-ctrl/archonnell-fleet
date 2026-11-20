<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Models\AuditLog;
use App\Services\VehicleService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VehicleController extends Controller
{
    protected VehicleService $vehicleService;

    public function __construct(VehicleService $vehicleService)
    {
        $this->vehicleService = $vehicleService;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'vehicle_code' => ['nullable', 'string', 'max:50'],
            'plate_number' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', 'string', 'max:100'],
            'status' => ['required', Rule::in(['Active', 'Available', 'In Transit', 'Reserved', 'Maintenance', 'Inactive'])],
        ]);

        $data['plate_number'] = strtoupper($data['plate_number']);
        if ($data['status'] === 'Available') $data['status'] = 'Active';

        // 1. Send to Centralized Backend Service FIRST
        $created = $this->vehicleService->createVehicle([
            'plate_number' => $data['plate_number'],
            'name' => $data['name'],
            'type' => $data['type'],
            'status' => $data['status'],
        ]);

        // Require successful centralized API confirmation
        if (!$created) {
            $errorMsg = $this->vehicleService->getClient()->getLastError()
                ?? 'Failed to save vehicle to the centralized backend.';
            return back()->withInput()->withErrors(['error' => $errorMsg]);
        }

        // 2. Only after centralized confirmation, record audit log & keep mirror in sync
        try {
            $data['vehicle_code'] = $created['vehicle_code'] ?? ($created['plate_number'] ?? $data['plate_number']);
            $data['odometer'] = (int) ($created['current_odometer'] ?? ($created['currentOdometer'] ?? 0));
            $data['odometer_auto_since'] = now()->toDateString();
            $vehicle = Vehicle::updateOrCreate(['plate_number' => $data['plate_number']], $data);
            AuditLog::record($request, 'Vehicle added', 'Fleet', "Added vehicle {$vehicle->plate_number} to the fleet.");
        } catch (\Throwable) {}

        return redirect()->route('vehicles')->with('success', 'Vehicle saved to the central fleet database.');
    }

    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'vehicle_code' => ['required', 'string', 'max:50'],
            'plate_number' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', 'string', 'max:100'],
            'status' => ['required', Rule::in(['Active', 'Available', 'In Transit', 'Reserved', 'Maintenance', 'Inactive'])],
        ]);

        $data['vehicle_code'] = strtoupper($data['vehicle_code']);
        $data['plate_number'] = strtoupper($data['plate_number']);
        if ($data['status'] === 'Available') $data['status'] = 'Active';

        // 1. Send to Centralized Backend Service FIRST
        $updated = $this->vehicleService->updateVehicle((string)$id, [
            'plate_number' => $data['plate_number'],
            'name' => $data['name'],
            'type' => $data['type'],
            'status' => $data['status'],
        ]);

        // Require successful centralized API confirmation
        if (!$updated) {
            $errorMsg = $this->vehicleService->getClient()->getLastError()
                ?? 'Failed to update vehicle in the centralized backend.';
            return back()->withInput()->withErrors(['error' => $errorMsg]);
        }

        // 2. Only after centralized confirmation, update local mirror
        try {
            $vehicle = is_numeric($id) ? Vehicle::find($id) : Vehicle::where('plate_number', $data['plate_number'])->first();
            if ($vehicle) {
                $vehicle->update($data);
                AuditLog::record($request, 'Vehicle updated', 'Fleet', "Updated {$vehicle->vehicle_code} ({$vehicle->plate_number}).");
            }
        } catch (\Throwable) {}

        return redirect()->route('vehicles')->with('success', 'Vehicle changes saved to the central fleet database.');
    }
}
