<?php

namespace App\Services;

class MaintenanceService
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

    public function getMaintenanceRecords(?string $vehicleId = null): ?array
    {
        $query = [];
        if ($vehicleId) $query['vehicle_id'] = $vehicleId;

        $res = $this->client->get('fleet/maintenance', $query);
        if ($res === null || !array_key_exists('data', $res)) {
            return null;
        }
        return (array) $res['data'];
    }

    public function logMaintenance(array $data): ?array
    {
        $res = $this->client->post('fleet/maintenance', [
            'vehicle_id' => $data['vehicle_id'] ?? $data['vehicleId'],
            'service_type' => $data['service_type'] ?? $data['serviceType'] ?? 'Routine Maintenance',
            'description' => $data['description'] ?? '',
            'cost' => (float) ($data['cost'] ?? 0),
            'serviced_at' => $data['serviced_at'] ?? $data['servicedAt'] ?? null,
        ]);
        if ($res === null) {
            return null;
        }
        return $res['data'] ?? $res;
    }
}
