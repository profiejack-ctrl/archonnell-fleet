<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\TripExpense;
use App\Services\TripExpenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TripExpenseController extends Controller
{
    protected TripExpenseService $tripExpenseService;

    public function __construct(TripExpenseService $tripExpenseService)
    {
        $this->tripExpenseService = $tripExpenseService;
    }

    private const CATEGORIES = ['Meals', 'Parking', 'Other'];

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'vehicle_id' => ['required', 'string'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'receipt' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $receiptUrl = null;
        $receiptPath = null;
        if ($request->hasFile('receipt')) {
            $receiptPath = $request->file('receipt')->store('trip-expenses/receipts', 'public');
            $receiptUrl = asset('storage/' . $receiptPath);
        }

        // 1. Submit to Centralized Backend Service FIRST
        $centralRes = $this->tripExpenseService->logTripExpense([
            'user_id' => (string)($request->user()->id),
            'vehicle_id' => $data['vehicle_id'],
            'category' => $data['category'],
            'description' => $data['description'],
            'amount' => (float)$data['amount'],
            'expense_date' => $data['expense_date'],
            'receipt_url' => $receiptUrl,
        ]);

        if (!$centralRes) {
            $errorMsg = $this->tripExpenseService->getClient()->getLastError()
                ?? 'Failed to save trip expense to the centralized backend.';
            return back()->withInput()->withErrors(['error' => $errorMsg]);
        }

        // 2. Only after centralized confirmation, record locally with receipt proof (resilient)
        try {
            $localVehicle = is_numeric($data['vehicle_id']) ? \App\Models\Vehicle::find($data['vehicle_id']) : null;
            if (!$localVehicle) {
                $localVehicle = \App\Models\Vehicle::first();
            }

            TripExpense::create([
                'user_id' => $request->user()->id,
                'vehicle_id' => $localVehicle?->id ?? 1,
                'category' => $data['category'],
                'description' => $data['description'],
                'amount' => $data['amount'],
                'expense_date' => $data['expense_date'],
                'receipt_path' => $receiptPath,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Local SQLite trip expense mirror skipped: ' . $e->getMessage());
        }

        AuditLog::record($request, 'Trip expense submitted', 'Fuel & Costs', sprintf('Submitted %s expense for PHP %s: %s.', $data['category'], number_format((float)$data['amount'], 2), $data['description']));

        return back()->with('status', 'Trip expense saved with receipt proof.');
    }

    public function receipt(Request $request, TripExpense $tripExpense): BinaryFileResponse
    {
        abort_unless($request->user() && ($request->user()->role === 'Admin' || (int) $tripExpense->user_id === (int) $request->user()->id), 404);
        abort_unless(Storage::disk('local')->exists($tripExpense->receipt_path), 404);

        return response()->file(Storage::disk('local')->path($tripExpense->receipt_path), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
