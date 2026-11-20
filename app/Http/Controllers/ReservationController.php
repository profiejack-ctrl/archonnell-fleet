<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\AuditLog;
use App\Services\ReservationService;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    protected ReservationService $reservationService;

    public function __construct(ReservationService $reservationService)
    {
        $this->reservationService = $reservationService;
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'nullable|string|max:50',
            'destination' => 'required|string|max:255',
            'requested_date' => 'required|date',
            'passenger_count' => 'required|integer|min:1|max:60',
            'purpose' => 'required|string|max:255',
            'vehicle_type' => 'nullable|string|max:100',
            'remarks' => 'nullable|string',
            'requested_time' => 'nullable',
        ]);

        $userId = (string)$request->user()?->id;

        // 1. Submit through Centralized Backend Service FIRST
        $created = $this->reservationService->createReservation([
            'user_id' => $userId,
            'driver_name' => $request->user()?->name ?? '',
            'employee_id' => $validated['employee_id'] ?? $userId,
            'vehicle_type' => $validated['vehicle_type'] ?? '',
            'destination' => $validated['destination'],
            'requested_date' => $validated['requested_date'],
            'requested_time' => $validated['requested_time'] ?? null,
            'duration_days' => 1,
            'passenger_count' => (int) $validated['passenger_count'],
            'purpose' => $validated['purpose'],
            'remarks' => $validated['remarks'] ?? '',
        ]);

        // Require successful centralized confirmation
        if (!$created) {
            $errorMsg = $this->reservationService->getClient()->getLastError()
                ?? 'Failed to submit reservation to the centralized backend.';
            return back()->withInput()->withErrors(['error' => $errorMsg]);
        }

        // 2. Only after centralized confirmation succeeds
        try {
            $validated['reservation_no'] = $created['reservation_no'] ?? ('RES-' . strtoupper((string) \Illuminate\Support\Str::uuid()));
            $validated['status'] = 'Pending';
            $validated['user_id'] = $request->user()?->id;
            Reservation::create($validated);
            AuditLog::record($request, 'Reservation submitted', 'Reservations', "Submitted reservation to {$validated['destination']}.");
        } catch (\Throwable) {}

        return back()->with('success', 'Reservation submitted and sent for approval to the central backend.');
    }

    public function approve(Request $request, $id)
    {
        $resId = is_object($id) ? (string)$id->id : (string)$id;

        // 1. Approve via Centralized Backend Service FIRST
        $approved = $this->reservationService->approveReservation($resId, (string)$request->user()?->id);
        if (!$approved) {
            $errorMsg = $this->reservationService->getClient()->getLastError()
                ?? 'Failed to approve reservation on centralized backend.';
            return back()->withErrors(['error' => $errorMsg]);
        }

        // 2. Only after centralized confirmation
        try {
            $reservation = is_numeric($resId) ? Reservation::find($resId) : Reservation::where('id', $resId)->first();
            if ($reservation) {
                $reservation->update([
                    'status' => 'Approved',
                    'approved_by' => $request->user()?->id,
                    'approved_at' => now(),
                ]);
            }
        } catch (\Throwable) {}

        return back()->with('success', 'Reservation approved successfully in central backend.');
    }

    public function reject(Request $request, $id)
    {
        $resId = is_object($id) ? (string)$id->id : (string)$id;

        // 1. Reject via Centralized Backend Service FIRST
        $rejected = $this->reservationService->rejectReservation($resId);
        if (!$rejected) {
            $errorMsg = $this->reservationService->getClient()->getLastError()
                ?? 'Failed to reject reservation on centralized backend.';
            return back()->withErrors(['error' => $errorMsg]);
        }

        // 2. Only after centralized confirmation
        try {
            $reservation = is_numeric($resId) ? Reservation::find($resId) : Reservation::where('id', $resId)->first();
            if ($reservation) {
                $reservation->update(['status' => 'Rejected']);
            }
        } catch (\Throwable) {}

        return back()->with('success', 'Reservation rejected in central backend.');
    }
}
