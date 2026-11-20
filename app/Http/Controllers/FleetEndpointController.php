<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceIssue;
use App\Models\MaintenanceRecord;
use App\Models\Reservation;
use App\Models\TripExpense;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FleetEndpointController extends Controller
{
    /**
     * GET /api/v1/fleet/reservations
     */
    public function getReservations(Request $request): JsonResponse
    {
        $query = Reservation::query()->with([
            'requester:id,name,email',
            'approver:id,name,email',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('vehicle_type')) {
            $query->where('vehicle_type', $request->string('vehicle_type')->toString());
        }

        $reservations = $query->orderByDesc('id')->get()->map(function (Reservation $r) {
            return [
                'id' => $r->id,
                'reservation_no' => $r->reservation_no,
                'employee_id' => $r->employee_id,
                'requester' => $r->requester?->name,
                'requester_email' => $r->requester?->email,
                'destination' => $r->destination,
                'purpose' => $r->purpose,
                'requested_date' => $r->requested_date?->format('Y-m-d') ?: (string) $r->requested_date,
                'requested_time' => $r->requested_time,
                'vehicle_type' => $r->vehicle_type,
                'passenger_count' => $r->passenger_count,
                'remarks' => $r->remarks,
                'status' => $r->status,
                'approved_by' => $r->approver?->name,
                'approved_at' => $r->approved_at?->toIso8601String(),
                'created_at' => $r->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $reservations,
        ]);
    }

    /**
     * POST /api/v1/fleet/reservations
     */
    public function storeReservation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'destination' => 'required|string|max:255',
            'purpose' => 'required|string|max:255',
            'requested_date' => 'required|date',
            'requested_time' => 'nullable|string',
            'vehicle_type' => 'nullable|string',
            'passenger_count' => 'nullable|integer|min:1',
            'remarks' => 'nullable|string',
            'employee_id' => 'nullable|string',
        ]);

        $user = auth('sanctum')->user() ?? auth('web')->user();
        $reservationNo = 'RSV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

        $reservation = Reservation::create([
            'reservation_no' => $reservationNo,
            'user_id' => $user?->id,
            'employee_id' => $validated['employee_id'] ?? ($user ? 'EMP-' . $user->id : 'EMP-0001'),
            'destination' => $validated['destination'],
            'purpose' => $validated['purpose'],
            'requested_date' => $validated['requested_date'],
            'requested_time' => $validated['requested_time'] ?? '08:00',
            'vehicle_type' => $validated['vehicle_type'] ?? 'Truck',
            'passenger_count' => $validated['passenger_count'] ?? 1,
            'remarks' => $validated['remarks'] ?? '',
            'status' => 'Pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Reservation created successfully.',
            'data' => $reservation,
        ], 201);
    }

    /**
     * GET /api/v1/fleet/maintenance
     */
    public function getMaintenance(Request $request): JsonResponse
    {
        $records = MaintenanceRecord::query()
            ->with('vehicle:id,vehicle_code,plate_number,name,type,status')
            ->orderByDesc('id')
            ->get()
            ->map(function (MaintenanceRecord $m) {
                return [
                    'id' => $m->id,
                    'type' => 'record',
                    'vehicle_id' => $m->vehicle_id,
                    'vehicle_code' => $m->vehicle?->vehicle_code,
                    'plate_number' => $m->vehicle?->plate_number,
                    'vehicle_name' => $m->vehicle?->name,
                    'description' => $m->description,
                    'cost' => (float) $m->cost,
                    'serviced_at' => (string) $m->serviced_at,
                    'created_at' => $m->created_at?->toIso8601String(),
                ];
            });

        $issues = MaintenanceIssue::query()
            ->with(['vehicle:id,vehicle_code,plate_number,name,type,status', 'reporter:id,name'])
            ->orderByDesc('id')
            ->get()
            ->map(function (MaintenanceIssue $i) {
                return [
                    'id' => $i->id,
                    'type' => 'issue',
                    'vehicle_id' => $i->vehicle_id,
                    'vehicle_code' => $i->vehicle?->vehicle_code,
                    'plate_number' => $i->vehicle?->plate_number,
                    'vehicle_name' => $i->vehicle?->name,
                    'reported_by' => $i->reporter?->name,
                    'details' => $i->details,
                    'status' => $i->status,
                    'resolved_at' => $i->resolved_at?->toIso8601String(),
                    'created_at' => $i->created_at?->toIso8601String(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'records' => $records,
                'issues' => $issues,
            ],
        ]);
    }

    /**
     * POST /api/v1/fleet/maintenance
     */
    public function storeMaintenance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id' => 'required|string',
            'description' => 'required|string|max:500',
            'cost' => 'required|numeric|min:0',
            'serviced_at' => 'nullable|date',
        ]);

        $vehicle = is_numeric($validated['vehicle_id'])
            ? Vehicle::find($validated['vehicle_id'])
            : Vehicle::where('plate_number', $validated['vehicle_id'])->first();

        $record = MaintenanceRecord::create([
            'vehicle_id' => $vehicle ? $vehicle->id : 1,
            'description' => $validated['description'],
            'cost' => $validated['cost'],
            'serviced_at' => $validated['serviced_at'] ?? now()->toDateString(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Maintenance record logged successfully.',
            'data' => $record,
        ], 201);
    }

    /**
     * GET /api/v1/fleet/trip-expenses
     */
    public function getTripExpenses(Request $request): JsonResponse
    {
        $query = TripExpense::query()->with([
            'vehicle:id,vehicle_code,plate_number,name',
            'user:id,name,email',
        ]);

        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->integer('vehicle_id'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->string('category')->toString());
        }

        $expenses = $query->orderByDesc('id')->get()->map(function (TripExpense $e) {
            return [
                'id' => $e->id,
                'vehicle_id' => $e->vehicle_id,
                'vehicle_code' => $e->vehicle?->vehicle_code,
                'plate_number' => $e->vehicle?->plate_number,
                'vehicle_name' => $e->vehicle?->name,
                'logged_by' => $e->user?->name,
                'category' => $e->category,
                'description' => $e->description,
                'amount' => (float) $e->amount,
                'expense_date' => (string) $e->expense_date,
                'receipt_path' => $e->receipt_path ? route('trip-expenses.receipt', $e->id) : null,
                'created_at' => $e->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $expenses,
        ]);
    }

    /**
     * POST /api/v1/fleet/trip-expenses
     */
    public function storeTripExpense(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id' => 'required|string',
            'category' => 'required|string|max:100',
            'description' => 'required|string|max:500',
            'amount' => 'required|numeric|min:0',
            'expense_date' => 'nullable|date',
        ]);

        $vehicle = is_numeric($validated['vehicle_id'])
            ? Vehicle::find($validated['vehicle_id'])
            : Vehicle::where('plate_number', $validated['vehicle_id'])->first();

        $user = auth('sanctum')->user() ?? auth('web')->user();

        $expense = TripExpense::create([
            'vehicle_id' => $vehicle ? $vehicle->id : 1,
            'user_id' => $user?->id ?? 1,
            'category' => $validated['category'],
            'description' => $validated['description'],
            'amount' => $validated['amount'],
            'expense_date' => $validated['expense_date'] ?? now()->toDateString(),
        ]);
            'user_id' => $user?->id,
            'vehicle_id' => $validated['vehicle_id'],
            'category' => $validated['category'],
            'description' => $validated['description'],
            'amount' => $validated['amount'],
            'expense_date' => $validated['expense_date'] ?? now()->toDateString(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Trip expense recorded successfully.',
            'data' => $expense,
        ], 201);
    }
}
