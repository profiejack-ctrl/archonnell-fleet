<?php

namespace App\Services;

class TripExpenseService
{
    protected CentralApiClient $client;

    public function __construct(CentralApiClient $client)
    {
        $this->client = $client;
    }

    public function getClient(): CentralApiClient
    {
        return $this->client;
    }

    public function getTripExpenses(?string $userId = null): ?array
    {
        $query = [];
        if ($userId) $query['user_id'] = $userId;

        $res = $this->client->get('fleet/trip-expenses', $query);
        if ($res === null || !array_key_exists('data', $res)) {
            return null;
        }
        return (array) $res['data'];
    }

    public function logTripExpense(array $data): ?array
    {
        $res = $this->client->post('fleet/trip-expenses', [
            'user_id' => $data['user_id'] ?? null,
            'vehicle_id' => $data['vehicle_id'] ?? null,
            'category' => $data['category'] ?? '',
            'description' => $data['description'] ?? '',
            'amount' => (float) ($data['amount'] ?? 0),
            'expense_date' => $data['expense_date'] ?? null,
            'receipt_url' => $data['receipt_url'] ?? null,
        ]);
        if ($res === null) {
            return null;
        }
        return $res['data'] ?? $res;
    }
}
