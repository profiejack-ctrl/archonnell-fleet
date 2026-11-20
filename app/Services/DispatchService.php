<?php

namespace App\Services;

class DispatchService
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

    public function getDispatches(array $filters = []): ?array
    {
        $res = $this->client->get('fleet/dispatches', $filters);
        if ($res === null || !array_key_exists('data', $res)) {
            return null;
        }
        return (array) $res['data'];
    }

    public function getDispatch(string $id): ?array
    {
        $res = $this->client->get("fleet/dispatches/{$id}");
        if ($res === null) {
            return null;
        }
        return $res['data'] ?? $res;
    }

    public function createDispatch(array $data): ?array
    {
        $payload = [
            'vehicle_id' => $data['vehicle_id'] ?? null,
            'driver_id' => $data['driver_id'] ?? null,
            'origin' => $data['origin'] ?? '',
            'destination' => $data['destination'] ?? '',
            'dispatch_no' => $data['dispatch_no'] ?? null,
            'status' => $data['status'] ?? 'Scheduled',
            'priority' => $data['priority'] ?? 'Normal',
        ];

        $res = $this->client->post('fleet/dispatches', $payload);
        if ($res !== null) {
            return $res['data'] ?? $res;
        }

        // Fallback to compatibility endpoint if necessary
        $res = $this->client->post('fleet/trip/start', [
            'vehicle_id' => $data['vehicle_id'] ?? null,
            'driver_id' => $data['driver_id'] ?? null,
            'trip_id' => $data['dispatch_no'] ?? null,
            'origin' => $data['origin'] ?? '',
            'destination' => $data['destination'] ?? '',
            'priority' => $data['priority'] ?? 'Normal',
            'status' => $data['status'] ?? 'Scheduled',
        ]);
        if ($res === null) {
            return null;
        }
        return $res['trip'] ?? $res['data'] ?? $res;
    }

    public function assignDispatch(string $id, string $vehicleId, string $driverId): ?array
    {
        $res = $this->client->patch("fleet/dispatches/{$id}/assign", [
            'vehicleId' => $vehicleId,
            'driverId' => $driverId,
        ]);
        if ($res === null) {
            return null;
        }
        return $res['data'] ?? $res;
    }

    public function startDispatch(string $id): ?array
    {
        $res = $this->client->post("fleet/dispatches/{$id}/start");
        if ($res === null) {
            return null;
        }
        return $res['data'] ?? $res;
    }

    public function completeDispatch(string $id, ?string $podUrl = null, ?float $endOdometer = null): ?array
    {
        $res = $this->client->post("fleet/dispatches/{$id}/complete", [
            'proofOfDeliveryUrl' => $podUrl,
            'endOdometer' => $endOdometer,
        ]);
        if ($res === null) {
            return null;
        }
        return $res['data'] ?? $res;
    }

    public function updateDispatchStatus(string $id, string $status): ?array
    {
        if (strtolower($status) === 'active') {
            return $this->startDispatch($id);
        }
        if (strtolower($status) === 'completed') {
            return $this->completeDispatch($id);
        }

        $res = $this->client->patch("fleet/dispatches/{$id}/status", [
            'status' => $status,
        ]);
        if ($res === null) {
            return null;
        }
        return $res['data'] ?? $res;
    }

    public function getActiveDispatches(): ?array
    {
        $res = $this->client->get('fleet/dispatches/active');
        if ($res === null) {
            return null;
        }
        return (array) ($res['trips'] ?? $res['dispatches'] ?? $res['data'] ?? []);
    }
}
