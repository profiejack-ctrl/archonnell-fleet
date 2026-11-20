<?php

namespace App\Http\Controllers;

use App\Models\Dispatch;
use App\Models\AuditLog;
use App\Models\Reservation;
use App\Models\TripRecord;
use App\Support\OpenRouteServiceGeocoder;
use App\Services\DispatchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DispatchController extends Controller
{
    protected DispatchService $dispatchService;

    public function __construct(DispatchService $dispatchService)
    {
        $this->dispatchService = $dispatchService;
    }

    public function searchPlaces(Request $request)
    {
        $query = trim((string) $request->query('q', ''));
        if (mb_strlen($query) < 2) return response()->json(['results' => []]);
        return response()->json(['results' => app(OpenRouteServiceGeocoder::class)->search($query)]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'vehicle_id' => 'required|string',
            'driver_id' => 'required|string',
            'destination' => 'required|string|max:255',
            'origin' => 'required|string|max:255',
            'origin_lat' => 'nullable|numeric|between:-90,90',
            'origin_lng' => 'nullable|numeric|between:-180,180',
            'dest_lat' => 'nullable|numeric|between:-90,90',
            'dest_lng' => 'nullable|numeric|between:-180,180',
            'priority' => 'nullable|in:Normal,High,Urgent',
        ]);

        $originalVehicleId = $validated['vehicle_id'];
        $originalDriverId = $validated['driver_id'];

        $geocoder = app(OpenRouteServiceGeocoder::class);
        $originCoordinates = isset($validated['origin_lat'], $validated['origin_lng']) && is_numeric($validated['origin_lat'])
            ? ['lat' => (float) $validated['origin_lat'], 'lng' => (float) $validated['origin_lng']]
            : ($geocoder->geocode($validated['origin']) ?? ['lat' => 14.2230, 'lng' => 120.9742]);

        $destinationCoordinates = isset($validated['dest_lat'], $validated['dest_lng']) && is_numeric($validated['dest_lat'])
            ? ['lat' => (float) $validated['dest_lat'], 'lng' => (float) $validated['dest_lng']]
            : ($geocoder->geocode($validated['destination']) ?? ['lat' => 14.5547, 'lng' => 121.0244]);

        $validated['dispatch_no'] = 'DSP-' . strtoupper(uniqid());
        $validated['status'] = 'Active';
        $validated['origin_lat'] = $originCoordinates['lat'];
        $validated['origin_lng'] = $originCoordinates['lng'];
        $validated['dest_lat'] = $destinationCoordinates['lat'];
        $validated['dest_lng'] = $destinationCoordinates['lng'];

        // Synchronize with Centralized Backend Service FIRST
        $created = $this->dispatchService->createDispatch([
            'vehicle_id' => $originalVehicleId,
            'driver_id' => $originalDriverId,
            'origin' => $validated['origin'],
            'destination' => $validated['destination'],
            'priority' => $validated['priority'] ?? 'Normal',
            'status' => 'Active',
            'dispatch_no' => $validated['dispatch_no'],
        ]);

        if (!$created) {
            $errorMsg = $this->dispatchService->getClient()->getLastError()
                ?? 'Failed to create dispatch on centralized backend.';
            return back()->withInput()->withErrors(['error' => $errorMsg]);
        }

        // Resilient local SQLite mirror
        try {
            $localVehicle = is_numeric($originalVehicleId)
                ? DB::table('vehicles')->where('id', $originalVehicleId)->first()
                : DB::table('vehicles')->where('plate_number', $originalVehicleId)->first();
            $localDriver = is_numeric($originalDriverId)
                ? DB::table('drivers')->where('id', $originalDriverId)->first()
                : null;

            $localValidated = $validated;
            $localValidated['vehicle_id'] = $localVehicle?->id ?? (DB::table('vehicles')->first()?->id ?? 1);
            $localValidated['driver_id'] = $localDriver?->id ?? (DB::table('drivers')->first()?->id ?? 1);

            DB::transaction(function () use ($localValidated) {
                $dispatch = Dispatch::create($localValidated);
                TripRecord::create([
                    'dispatch_id' => $dispatch->id,
                    'vehicle_id' => $dispatch->vehicle_id,
                    'driver_id' => $dispatch->driver_id,
                    'origin' => $dispatch->origin,
                    'destination' => $dispatch->destination,
                    'origin_lat' => $dispatch->origin_lat,
                    'origin_lng' => $dispatch->origin_lng,
                    'dest_lat' => $dispatch->dest_lat,
                    'dest_lng' => $dispatch->dest_lng,
                    'departure_time' => now(),
                    'status' => 'Active',
                ]);
                DB::table('vehicles')->where('id', $dispatch->vehicle_id)->update(['status' => 'In Transit', 'updated_at' => now()]);
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Local SQLite dispatch mirror skipped: ' . $e->getMessage());
        }

        AuditLog::record($request, 'Dispatch created', 'Dispatches', "Created dispatch from {$validated['origin']} to {$validated['destination']}.");

        return back()->with('success', 'Direct Dispatch created successfully.');
    }

    public function convert(Request $request, $reservation)
    {
        $validated = $request->validate([
            'vehicle_id' => 'required|string',
            'driver_id' => 'required|string',
        ]);

        $resId = is_object($reservation) ? (string)($reservation->id ?? '') : (string)$reservation;

        // Synchronize with Centralized Backend Service FIRST:
        // Attempt assignment on centralized dispatch
        $assigned = $this->dispatchService->assignDispatch($resId, $validated['vehicle_id'], $validated['driver_id']);

        if (!$assigned) {
            // Fallback: create fresh dispatch
            $assigned = $this->dispatchService->createDispatch([
                'vehicle_id' => $validated['vehicle_id'],
                'driver_id' => $validated['driver_id'],
                'origin' => 'Main Chemical Plant, Silang, Cavite',
                'destination' => $request->input('destination', 'Client Site'),
                'priority' => 'Normal',
                'status' => 'Scheduled',
            ]);
        }

        if (!$assigned) {
            $errorMsg = $this->dispatchService->getClient()->getLastError()
                ?? 'Failed to convert reservation on centralized backend.';
            return back()->withErrors(['error' => $errorMsg]);
        }

        // Resilient local mirror (optional, won't crash if record missing)
        try {
            $localRes = is_numeric($resId) ? Reservation::find($resId) : Reservation::where('id', $resId)->first();
            if ($localRes) {
                $localRes->update(['status' => 'Dispatched']);
            }
        } catch (\Throwable) {}

        AuditLog::record($request, 'Reservation dispatched', 'Dispatches', "Converted reservation {$resId} into a dispatch.");

        return back()->with('success', 'Reservation converted to Dispatch successfully.');
    }

    public function updateStatus(Request $request, $dispatch)
    {
        $newStatus = $request->input('status');
        $dispatchId = is_object($dispatch) ? (string)($dispatch->id ?? '') : (string)$dispatch;

        $dispatchModel = is_object($dispatch) ? $dispatch : (Dispatch::find($dispatchId) ?? Dispatch::where('dispatch_no', $dispatchId)->first());
        $currentStatus = $dispatchModel ? $dispatchModel->status : 'Scheduled';

        // 1. Synchronize with Centralized Backend Service FIRST
        $updated = $this->dispatchService->updateDispatchStatus($dispatchId, $newStatus);
        if (!$updated) {
            $errorMsg = $this->dispatchService->getClient()->getLastError()
                ?? 'Failed to update dispatch status on centralized backend.';
            return back()->withErrors(['error' => $errorMsg]);
        }

        // 2. Only after centralized confirmation, update local mirror
        try {
            if ($dispatchModel) {
                $dispatchModel->update(['status' => $newStatus]);

                if ($newStatus === 'Active') {
                    DB::table('vehicles')->where('id', $dispatchModel->vehicle_id)->update([
                        'status' => 'In Transit',
                        'updated_at' => now()
                    ]);
                }
                if ($newStatus === 'Completed' || $newStatus === 'Cancelled') {
                    DB::table('vehicles')->where('id', $dispatchModel->vehicle_id)->update(['status' => 'Active', 'updated_at' => now()]);
                    $trip = TripRecord::where('dispatch_id', $dispatchModel->id)->where('status', 'Active')->first();
                    if ($trip) {
                        $trip->update([
                            'status' => 'Completed',
                            'actual_arrival' => now(),
                            'total_duration' => (int) max(0, round(now()->diffInMinutes($trip->departure_time)))
                        ]);
                    }
                }
                AuditLog::record($request, 'Dispatch status changed', 'Dispatches', "Changed dispatch {$dispatchModel->dispatch_no} status from {$currentStatus} to {$newStatus}.");
            }
        } catch (\Throwable) {}

        return back()->with('success', 'Dispatch status updated to ' . $newStatus . ' successfully.');
    }
}
