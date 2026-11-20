<?php

namespace App\Services;

class FuelService
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

    public function getFuelLogs(?string $vehicleId = null): ?array
    {
        $query = [];
        if ($vehicleId) $query['vehicle_id'] = $vehicleId;

        $res = $this->client->get('fleet/fuel', $query);
        if ($res === null || !array_key_exists('data', $res)) {
            return null;
        }
        return (array) $res['data'];
    }

    public function logFuel(array $data): ?array
    {
        $vehicleId = (string)($data['vehicle_id'] ?? $data['vehicleId'] ?? '');
        $payload = [
            'vehicleId' => $vehicleId,
            'liters' => (float) ($data['liters'] ?? 0),
            'cost' => (float) ($data['cost'] ?? 0),
        ];

        $driverId = $data['driver_id'] ?? $data['driverId'] ?? null;
        if (!empty($driverId) && \Illuminate\Support\Str::isUuid((string)$driverId)) {
            $payload['driverId'] = (string)$driverId;
        }

        if (isset($data['odometer']) && $data['odometer'] !== null && $data['odometer'] !== '') {
            $payload['odometer'] = (float)$data['odometer'];
        }

        $receiptUrl = $data['receipt_url'] ?? $data['receiptUrl'] ?? null;
        if (!empty($receiptUrl) && is_string($receiptUrl)) {
            $payload['receiptUrl'] = $receiptUrl;
        }

        $res = $this->client->post('fleet/fuel', $payload);
        if ($res === null) {
            return null;
        }
        return $res['data'] ?? $res;
    }
}
