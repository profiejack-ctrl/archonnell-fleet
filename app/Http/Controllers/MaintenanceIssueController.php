<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\MaintenanceIssue;
use App\Models\Vehicle;
use App\Services\MaintenanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MaintenanceIssueController extends Controller
{
    protected MaintenanceService $maintenanceService;

    public function __construct(MaintenanceService $maintenanceService)
    {
        $this->maintenanceService = $maintenanceService;
    }

    private const STATUSES = ['Reported', 'In Progress', 'Waiting for Parts', 'Resolved'];

    public function store(Request $request, $vehicle): RedirectResponse
    {
        abort_unless($request->user()?->role === 'Admin', 403);

        $data = $request->validate([
            'details' => ['required', 'string', 'max:5000'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $vehicleId = is_object($vehicle) ? (string) $vehicle->id : (string) $vehicle;

        // 1. Submit through Centralized Backend Service FIRST
        $centralRes = $this->maintenanceService->logMaintenance([
            'vehicle_id' => $vehicleId,
            'service_type' => 'Reported Damage / Issue',
            'description' => $data['details'],
            'cost' => 0.0,
            'serviced_at' => now()->toDateString(),
        ]);

        if (!$centralRes) {
            $errorMsg = $this->maintenanceService->getClient()->getLastError()
                ?? 'Failed to save maintenance issue to the centralized backend.';
            return back()->withInput()->withErrors(['error' => $errorMsg]);
        }

        // 2. Only after centralized confirmation, save local mirror
        $localVehicle = is_numeric($vehicleId)
            ? Vehicle::find($vehicleId)
            : Vehicle::where('plate_number', $vehicleId)->first();

        if (!$localVehicle && !is_numeric($vehicleId)) {
            try {
                $localVehicle = Vehicle::firstOrCreate(
                    ['plate_number' => $vehicleId],
                    ['name' => 'Vehicle', 'vehicle_code' => $vehicleId, 'status' => 'Active', 'fuel_level' => 50, 'odometer' => 0]
                );
            } catch (\Throwable) {}
        }

        $localVehicleId = $localVehicle ? $localVehicle->id : (Vehicle::first()?->id ?? 1);

        try {
            $issue = MaintenanceIssue::create([
                'vehicle_id' => $localVehicleId,
                'reported_by' => $request->user()->id,
                'details' => $data['details'],
                'photo_path' => $request->file('photo')?->store('maintenance-issues', 'local'),
                'status' => 'Reported',
            ]);

            $vehicleCode = $localVehicle->vehicle_code ?? $vehicleId;
            AuditLog::record($request, 'Maintenance issue reported', 'Fleet', "Reported damage for {$vehicleCode}: {$issue->details}");
        } catch (\Throwable) {}

        return back()->with('success', 'Maintenance issue reported successfully.');
    }

    public function update(Request $request, $maintenanceIssue): RedirectResponse
    {
        abort_unless($request->user()?->role === 'Admin', 403);

        $data = $request->validate(['status' => ['required', Rule::in(self::STATUSES)]]);

        $issue = $maintenanceIssue instanceof MaintenanceIssue ? $maintenanceIssue : MaintenanceIssue::find($maintenanceIssue);
        if ($issue) {
            $previousStatus = $issue->status;
            $issue->status = $data['status'];
            $issue->resolved_at = $data['status'] === 'Resolved' ? now() : null;
            $issue->save();

            AuditLog::record($request, 'Maintenance status updated', 'Fleet', "Changed maintenance status from {$previousStatus} to {$issue->status}.");
        }

        return back()->with('success', 'Maintenance status updated.');
    }

    public function photo(Request $request, MaintenanceIssue $maintenanceIssue): BinaryFileResponse
    {
        abort_unless($request->user(), 401);
        abort_unless($maintenanceIssue->photo_path && Storage::disk('local')->exists($maintenanceIssue->photo_path), 404);

        return response()->file(Storage::disk('local')->path($maintenanceIssue->photo_path), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
