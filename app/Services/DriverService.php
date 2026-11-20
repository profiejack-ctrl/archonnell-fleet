<?php

namespace App\Services;

class DriverService
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

    public function getDrivers(?bool $onlyAvailable = null): ?array
    {
        $query = [];
        if ($onlyAvailable !== null) {
            $query['available'] = $onlyAvailable ? 'true' : 'false';
        }

        $res = $this->client->get('fleet/drivers', $query);
        if ($res === null || !array_key_exists('data', $res)) {
            return null;
        }
        return (array) $res['data'];
    }

    public function createDriver(array $data): ?array
    {
        $res = $this->client->post('fleet/drivers', [
            'employeeId' => $data['employee_id'] ?? $data['employeeId'],
            'licenseNumber' => $data['license_number'] ?? $data['licenseNumber'],
            'licenseExpiry' => $data['license_expiry'] ?? $data['licenseExpiry'] ?? null,
        ]);
        if ($res === null) {
            return null;
        }
        return $res['data'] ?? $res;
    }
}
