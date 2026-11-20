<?php

namespace App\Services;

class RouteService
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

    public function getTripRoute(string $tripId): ?array
    {
        return $this->client->get("fleet/trip/{$tripId}/route");
    }

    public function getTripEta(string $tripId): ?array
    {
        return $this->client->get("fleet/trip/{$tripId}/eta");
    }

    public function startTrip(array $data): ?array
    {
        return $this->client->post('fleet/trip/start', $data);
    }

    public function updateLocation(array $data): ?array
    {
        $payload = [
            'vehicleId' => $data['vehicleId'] ?? $data['vehicle_id'] ?? null,
            'latitude' => isset($data['latitude']) ? (float)$data['latitude'] : 0.0,
            'longitude' => isset($data['longitude']) ? (float)$data['longitude'] : 0.0,
        ];
        if (!empty($data['driver_id']) || !empty($data['driverId'])) {
            $payload['driverId'] = $data['driverId'] ?? $data['driver_id'];
        }
        if (!empty($data['dispatch_id']) || !empty($data['dispatchId'])) {
            $payload['dispatchId'] = $data['dispatchId'] ?? $data['dispatch_id'];
        }
        if (isset($data['speed'])) {
            $payload['speed'] = (float)$data['speed'];
        }
        if (isset($data['heading'])) {
            $payload['heading'] = (float)$data['heading'];
        }

        return $this->client->post('fleet/location/update', $payload);
    }

    public function getRouteLocations(string $dispatchId): ?array
    {
        $res = $this->client->get("fleet/dispatches/{$dispatchId}/locations");
        if ($res === null || !array_key_exists('data', $res)) {
            return null;
        }
        return (array) $res['data'];
    }

    public function handleSystemIntegration(array $data): ?array
    {
        return $this->client->post('fleet/integration/system', $data);
    }
}
