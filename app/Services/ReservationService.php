<?php

namespace App\Services;

class ReservationService
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

    public function getReservations(?string $status = null, ?string $userId = null): ?array
    {
        $query = [];
        if ($status) $query['status'] = $status;
        if ($userId) $query['user_id'] = $userId;

        $res = $this->client->get('fleet/reservations', $query);
        if ($res === null || !array_key_exists('data', $res)) {
            return null;
        }
        return (array) $res['data'];
    }

    public function createReservation(array $data): ?array
    {
        $res = $this->client->post('fleet/reservations', [
            'user_id' => $data['user_id'] ?? null,
            'driver_name' => $data['driver_name'] ?? null,
            'employee_id' => $data['employee_id'] ?? null,
            'vehicle_type' => $data['vehicle_type'] ?? null,
            'destination' => $data['destination'] ?? '',
            'requested_date' => $data['requested_date'] ?? null,
            'requested_time' => $data['requested_time'] ?? null,
            'duration_days' => $data['duration_days'] ?? null,
            'passenger_count' => $data['passenger_count'] ?? null,
            'purpose' => $data['purpose'] ?? '',
            'remarks' => $data['remarks'] ?? '',
        ]);
        if ($res === null) {
            return null;
        }
        return $res['data'] ?? $res;
    }

    public function approveReservation(string $id, ?string $approvedBy = null): ?array
    {
        $res = $this->client->post("fleet/reservations/{$id}/approve", [
            'approved_by' => $approvedBy,
        ]);
        if ($res === null) {
            return null;
        }
        return $res['data'] ?? $res;
    }

    public function rejectReservation(string $id): ?array
    {
        $res = $this->client->post("fleet/reservations/{$id}/reject");
        if ($res === null) {
            return null;
        }
        return $res['data'] ?? $res;
    }
}
