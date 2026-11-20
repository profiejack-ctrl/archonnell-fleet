<?php

namespace App\Services;

class VehicleService
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

    public function getVehicles(?string $status = null): ?array
    {
        $query = [];
        if ($status !== null) {
            $query['status'] = $status;
        }

        $res = $this->client->get('fleet/vehicles', $query);
        if ($res === null || !array_key_exists('data', $res)) {
            return null;
        }
        return (array) $res['data'];
    }

    public function getVehicle(string $id): ?array
    {
        $res = $this->client->get("fleet/vehicles/{$id}");
        if ($res === null) {
            return null;
        }
        return $res['data'] ?? $res;
    }

    public function createVehicle(array $data): ?array
    {
        $payload = [
            'plateNumber' => $data['plate_number'] ?? $data['plateNumber'] ?? '',
            'model' => $data['name'] ?? $data['model'] ?? '',
        ];
        if (isset($data['type']) || isset($data['vehicleType'])) {
            $payload['vehicleType'] = $data['type'] ?? $data['vehicleType'];
        }
        if (isset($data['capacity_kg'])) {
            $payload['capacityKg'] = (float)$data['capacity_kg'];
        }
        if (isset($data['fuel_capacity_liters'])) {
            $payload['fuelCapacityLiters'] = (float)$data['fuel_capacity_liters'];
        }
        if (isset($data['odometer']) || isset($data['currentOdometer'])) {
            $payload['currentOdometer'] = (float)($data['odometer'] ?? $data['currentOdometer']);
        }

        $res = $this->client->post('fleet/vehicles', $payload);
        if ($res === null) {
            return null;
        }
        return $res['data'] ?? $res;
    }

    public function updateVehicle(string $id, array $data): ?array
    {
        $payload = [];
        if (isset($data['plate_number'])) $payload['plateNumber'] = $data['plate_number'];
        if (isset($data['name']) || isset($data['model'])) $payload['model'] = $data['name'] ?? $data['model'];
        if (isset($data['type'])) $payload['vehicleType'] = $data['type'];
        if (isset($data['status'])) $payload['status'] = $data['status'];
        if (isset($data['odometer'])) $payload['currentOdometer'] = (float) $data['odometer'];

        $res = $this->client->put("fleet/vehicles/{$id}", $payload);
        if ($res === null || empty($res['data'])) {
            return null;
        }
        return $res['data'];
    }

    public function getLiveVehiclesTelemetry(): ?array
    {
        $res = $this->client->get('fleet/vehicles/live');
        if ($res === null) {
            return null;
        }
        return (array) ($res['vehicles'] ?? $res['data'] ?? []);
    }
}
